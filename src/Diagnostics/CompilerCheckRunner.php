<?php

declare(strict_types=1);

namespace XPHP\Lsp\Diagnostics;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;
use Phpactor\LanguageServerProtocol\DiagnosticSeverity;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use XPHP\Config\ManifestResolver;
use XPHP\Config\SourceResolver;
use XPHP\Diagnostics\Diagnostic as CompilerDiagnostic;
use XPHP\Diagnostics\Severity;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\Lsp\Project\XphpManifest;
use XPHP\Lsp\Stderr;
use XPHP\Transpiler\Monomorphize\Compiler;
use XPHP\Transpiler\Monomorphize\SpecializedClassGenerator;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Drives the compiler's own in-process validator over the whole source set and
 * returns per-URI LSP diagnostics.
 *
 * This is the *authoritative* diagnostics tier: unlike the tolerant per-keystroke
 * pass (which sees only open buffers and reaches only return-position closure
 * conformance), {@see Compiler::check()} runs the complete generic-validation
 * suite — including the grounded, call-argument closure-conformance and bound
 * checks — over every file in the project. It is a closed-world analysis: it
 * validates exactly the files handed to it, so we feed it the whole source set
 * (resolved the same way `xphp check` does, via {@see SourceResolver}), not the
 * single active file.
 *
 * `check()` reads from disk, so this is meant to run on SAVE, when the editor
 * buffer is flushed. It never throws: an absent/malformed manifest, an
 * unreadable root, or any failure inside the compiler yields an empty result and
 * leaves the tolerant tier untouched.
 */
final class CompilerCheckRunner implements DiagnosticsCheckSource
{
    /**
     * Coarse safety valve. `check()` is synchronous and monomorphizes the entire
     * source set, so a large set would block the LSP event loop for many seconds
     * (measured ~66s over an 800-file tree). Above this many resolved files the
     * pass is skipped rather than run in-process. A scoped `xphp.json` keeps real
     * projects well under this; this only trips on unscoped / very large trees.
     */
    public const DEFAULT_MAX_FILES = 200;

    private ?SourceResolver $resolver;

    private ?Compiler $compiler;

    public function __construct(
        private readonly string $rootPath,
        ?SourceResolver $resolver = null,
        ?Compiler $compiler = null,
        private readonly int $maxFiles = self::DEFAULT_MAX_FILES,
    ) {
        $this->resolver = $resolver;
        $this->compiler = $compiler;
    }

    /**
     * Validate the whole source set and return LSP diagnostics keyed by file URI.
     * Empty when there is nothing to check or anything goes wrong.
     *
     * @return array<string, list<LspDiagnostic>>
     */
    public function run(): array
    {
        // Load-bearing guard, NOT just an optimization: without the `return []`,
        // an empty root would fall through to resolveSources() -> XphpManifest::locate('')
        // which walks up from the process CWD and could pick up an UNRELATED
        // ancestor xphp.json. The `||`->`&&` mutant is equivalent (both an empty
        // and a non-existent root funnel to [] either way), but dropping the return
        // is a real behaviour change, so only LogicalOr is ignored here.
        // @infection-ignore-all LogicalOr
        if ($this->rootPath === '' || !is_dir($this->rootPath)) {
            return [];
        }

        $startedAt = hrtime(true);
        try {
            $resolved = $this->resolveSources();
            if ($resolved === null) {
                return [];
            }
            $fileCount = count($resolved->files->filepaths);
            if ($fileCount > $this->maxFiles) {
                // @infection-ignore-all MethodCallRemoval -- observability only; the
                // stderr log has no behavioural contract (and is suppressed under
                // XPHP_LSP_QUIET in tests). The `return []` below is the behaviour.
                $this->log(sprintf(
                    'skipped: %d source files exceeds the in-process cap (%d) — scope with an xphp.json to enable',
                    $fileCount,
                    $this->maxFiles,
                ));
                return [];
            }
            $collector = $this->compiler()->check($resolved->files);
        } catch (\Throwable $e) {
            // Defensive: the authoritative tier must never take the server down.
            // A resolver RuntimeException (no sources) or any compiler-internal
            // failure simply means "no authoritative diagnostics this pass".
            $this->log(sprintf('caught %s: %s (%s)', $e::class, $e->getMessage(), self::elapsed($startedAt)));
            return [];
        }

        $byUri = $this->groupAndMap($collector->all());
        // @infection-ignore-all MethodCallRemoval -- observability only (see above).
        $this->log(sprintf(
            'ran: %d files -> %d diagnostics across %d files (%s)',
            $fileCount,
            array_sum(array_map('count', $byUri)),
            count($byUri),
            self::elapsed($startedAt),
        ));
        return $byUri;
    }

    // Pure observability helpers: output is stderr log text with no behavioural
    // contract (suppressed under XPHP_LSP_QUIET in tests), and the elapsed-ms
    // arithmetic is non-deterministic so it can't be asserted.
    private function log(string $message): void
    {
        // @infection-ignore-all
        Stderr::write(sprintf("[xphp-lsp authoritative] %s\n", $message));
    }

    private static function elapsed(int $startedAt): string
    {
        // @infection-ignore-all
        return sprintf('%.0f ms', (hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * Resolve the project's source set exactly as `xphp check` does: prefer an
     * `xphp.json` manifest (walks its source roots, excludes target/cache), else
     * treat the workspace root itself as a single source directory.
     */
    private function resolveSources(): ?\XPHP\Config\ResolvedSources
    {
        $manifest = XphpManifest::locate($this->rootPath);
        if ($manifest !== null) {
            return $this->resolver()->resolve(null, $manifest, $this->rootPath);
        }
        return $this->resolver()->resolve($this->rootPath, null, $this->rootPath);
    }

    /**
     * @param  list<CompilerDiagnostic> $diagnostics
     * @return array<string, list<LspDiagnostic>>
     */
    private function groupAndMap(array $diagnostics): array
    {
        /** @var array<string, list<LspDiagnostic>> $byUri */
        $byUri = [];
        /** @var array<string, list<string>> $lineCache */
        $lineCache = [];

        foreach ($diagnostics as $d) {
            $location = $d->location;
            // @infection-ignore-all Continue_ -- at most one location-less
            // diagnostic occurs per pass (an incomplete-set undefined-template
            // note), so skipping vs stopping is indistinguishable. Pinned by the
            // use_only fixture (testClosedWorldContract...).
            if ($location === null) {
                // No file to route it to. Whole-set passes shouldn't produce these;
                // drop defensively (dereferencing a null location would fatal).
                continue;
            }
            $file = $location->file;
            // The grounded per-specialization pass reports some diagnostics against
            // a SYNTHETIC path ("<specialized:XPHP\Generated\...>") that maps to no
            // real source line. Publishing that as a URI would put a phantom entry
            // in the client's Problems panel pointing at an unopenable path while
            // the real line stays unmarked — worse than dropping it. Skip anything
            // that isn't a real on-disk file (check() only ever reads real files).
            // @infection-ignore-all Continue_ -- skipping vs stopping is
            // indistinguishable here (the synthetic diagnostics we drop don't
            // precede real ones in the fixtures). Pinned by the selfcall fixture.
            if (!is_file($file)) {
                continue;
            }
            $uri = 'file://' . $file;
            // @infection-ignore-all GreaterThan IncrementInteger DecrementInteger
            // -- the compiler reports lines >= 1 (nikic getStartLine), so the `0`
            // boundary of this `?: 1` fallback is never crossed. Same rationale as
            // Compiler.php's own ignore of the identical construct.
            $line = $location->line > 0 ? $location->line : 1;

            $byUri[$uri][] = $this->toLsp($d, $this->lineLength($file, $line, $lineCache), $line);
        }

        return $byUri;
    }

    private function toLsp(CompilerDiagnostic $d, int $lineLength, int $line): LspDiagnostic
    {
        // The compiler reports line-only (1-based); render a full-line range.
        $zeroLine = $line - 1;
        $lsp = new LspDiagnostic(
            range: new Range(
                new Position($zeroLine, 0),
                new Position($zeroLine, $lineLength),
            ),
            message: $d->message,
        );
        $lsp->severity = self::severity($d->severity);
        $lsp->code = $d->code;
        $lsp->source = 'xphp';
        return $lsp;
    }

    /**
     * Length of the 1-based $line in $file, for a full-line range. Reads and
     * caches the file's lines; 0 when the file or line can't be read (yields a
     * zero-width range at column 0 — still a valid, if thin, squiggle).
     *
     * @param array<string, list<string>> $cache
     */
    private function lineLength(string $file, int $line, array &$cache): int
    {
        if (!isset($cache[$file])) {
            $source = @file_get_contents($file);
            $cache[$file] = $source === false ? [] : explode("\n", $source);
        }
        $lines = $cache[$file];
        $text = $lines[$line - 1] ?? '';
        // Trim a trailing CR so CRLF files don't over-extend the range by one.
        return strlen(rtrim($text, "\r"));
    }

    private static function severity(Severity $severity): int
    {
        return match ($severity) {
            Severity::Error => DiagnosticSeverity::ERROR,
            Severity::Warning => DiagnosticSeverity::WARNING,
            // NOTE (mutation): removing this arm survives — check()'s surface emits
            // only Error/Warning (Notice appears solely in the phpstan gate +
            // renderers, which the LSP path never runs), so the arm is unreachable
            // here and can't be exercised by a fixture. Kept for completeness.
            Severity::Notice => DiagnosticSeverity::INFORMATION,
        };
    }

    private function resolver(): SourceResolver
    {
        if ($this->resolver === null) {
            $finder = new NativeFileFinder();
            $this->resolver = new SourceResolver(
                $finder,
                new ManifestResolver(new NativeFileReader(), $finder),
            );
        }
        return $this->resolver;
    }

    private function compiler(): Compiler
    {
        if ($this->compiler === null) {
            $printer = new StandardPrinter();
            $writer = new NativeFileWriter();
            $this->compiler = new Compiler(
                new NativeFileReader(),
                $writer,
                new XphpSourceParser((new ParserFactory())->createForHostVersion()),
                new Specializer(),
                new SpecializedClassGenerator($printer, $writer),
                $printer,
            );
        }
        return $this->compiler;
    }
}
