<?php

declare(strict_types=1);

namespace XPHP\Lsp\Diagnostics;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Diagnostics\DiagnosticsProvider;
use Phpactor\LanguageServer\Core\Server\ClientApi;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use XPHP\Lsp\Analyzer\Diagnostic;
use XPHP\Lsp\Analyzer\DiagnosticCode;
use XPHP\Lsp\Analyzer\DiagnosticSeverity;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Analyzer\ParseResult;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Resolver\GenericResolver;

/**
 * Bridges the xphp analyzer (per-file + cross-file) to phpactor's diagnostics engine.
 *
 * Strategy:
 *   1. Parse the document being linted via the per-file Analyzer. If it has syntax
 *      errors we surface those and skip the workspace pass for it (the AST is unusable).
 *   2. Parse every other currently-open document in the phpactor workspace via the
 *      same Analyzer.
 *   3. Run the WorkspaceAnalyzer over the parsed set ONCE, producing a per-file
 *      diagnostic map for every open document. It catches bound violations,
 *      duplicate templates, hash collisions, and argument-type mismatches.
 *   4. Return the diagnostics keyed to the document we were asked about.
 *   5. Cross-file broadcast (push path only): because a single workspace pass
 *      already computes diagnostics for EVERY open document, editing `Box.xphp`
 *      can re-publish the now-stale diagnostics of dependents like `Use.xphp`
 *      without waiting for the user to touch them. We push a
 *      `textDocument/publishDiagnostics` for each OTHER open document whose
 *      diagnostics changed since we last published them. The document being
 *      linted is left to the engine (it publishes that one itself).
 *
 * Translates framework-neutral Diagnostic → LSP-wire Diagnostic at the boundary
 * via DiagnosticTranslator.
 */
final class XphpDiagnosticsProvider implements DiagnosticsProvider
{
    /**
     * Per-URI signature of the diagnostics last broadcast for that document,
     * so a re-lint that doesn't change a dependent's diagnostics doesn't
     * re-publish them (avoids flooding the client on rapid edit storms).
     *
     * @var array<string, string>
     */
    private array $lastBroadcast = [];

    public function __construct(
        private readonly ParsedDocumentCache $cache,
        private readonly WorkspaceAnalyzer $workspaceAnalyzer,
        private readonly PhpactorWorkspace $workspace,
        private readonly FqnIndex $fqnIndex,
        /**
         * Client handle used to push diagnostics for dependent documents. Null in
         * pull-mode / unit contexts that don't broadcast; when null, step 5 above
         * is skipped and the provider behaves exactly as the per-document linter.
         */
        private readonly ?ClientApi $clientApi = null,
        /**
         * Type-inference engine used for the conservative null-dereference
         * diagnostic (`xphp.null-deref`). Optional: resolver-less contexts
         * (most unit tests) simply skip that diagnostic and behave as before.
         */
        private readonly ?GenericResolver $genericResolver = null,
    ) {
    }

    public function provideDiagnostics(TextDocumentItem $textDocument, CancellationToken $cancel): Promise
    {
        $byUri = $this->computeAllOpenDiagnostics($textDocument);
        $this->broadcastDependents($textDocument->uri, $byUri);

        return new Success($byUri[$textDocument->uri] ?? []);
    }

    public function name(): string
    {
        return 'xphp';
    }

    /**
     * Sync entry-point shared by the push-mode `provideDiagnostics`
     * (above) and the pull-mode `textDocument/diagnostic` handler.
     * Pull mode never broadcasts -- it returns the requested document's
     * diagnostics and nothing else.
     *
     * @return list<LspDiagnostic>
     */
    public function analyzeSync(TextDocumentItem $textDocument): array
    {
        return $this->computeAllOpenDiagnostics($textDocument)[$textDocument->uri] ?? [];
    }

    /**
     * Run a single workspace pass and return the merged (per-file syntax +
     * cross-file) LSP diagnostics for EVERY open document, keyed by URI. The
     * document being linted is included like any other; callers pick the
     * entries they need.
     *
     * @return array<string, list<LspDiagnostic>>
     */
    private function computeAllOpenDiagnostics(TextDocumentItem $textDocument): array
    {
        $currentUri = $textDocument->uri;

        // Per-file syntax pass on the document being linted. Cache-keyed by
        // (uri, version) so subsequent hover/definition/completion calls
        // against the same unchanged document don't reparse.
        $currentResult = $this->cache->getOrParse(
            $currentUri,
            $textDocument->version,
            $textDocument->text,
        );

        // Per-file (syntax) diagnostics + the {uri: {ast, source}} map for the
        // workspace pass, covering the current document plus every OTHER open
        // document. Documents that fail to parse contribute their syntax
        // diagnostics but are kept out of the workspace pass (unusable AST).
        // Per-URI (version, source) so the workspace-diagnostics pass below can
        // clamp its ranges against the same buffer the diagnostics were computed
        // from (reusing the cached PositionMap).
        $docMeta = [$currentUri => ['version' => $textDocument->version, 'source' => $textDocument->text]];
        $perFileByUri = [
            $currentUri => $this->toLspClamped(
                array_merge(
                    $currentResult->diagnostics,
                    $this->collectNullDerefDiagnostics($currentUri, $textDocument->version, $textDocument->text, $currentResult),
                ),
                $currentUri,
                $textDocument->version,
                $textDocument->text,
            ),
        ];
        $parsedFiles = [];
        if ($currentResult->ast !== null) {
            $parsedFiles[$currentUri] = ['ast' => $currentResult->ast, 'source' => $textDocument->text];
        }
        foreach ($this->workspace as $uri => $item) {
            if ($uri === $currentUri) {
                continue;
            }
            $otherResult = $this->cache->getOrParse($uri, $item->version, $item->text);
            $docMeta[$uri] = ['version' => $item->version, 'source' => $item->text];
            $perFileByUri[$uri] = $this->toLspClamped(
                array_merge(
                    $otherResult->diagnostics,
                    $this->collectNullDerefDiagnostics($uri, $item->version, $item->text, $otherResult),
                ),
                $uri,
                $item->version,
                $item->text,
            );
            if ($otherResult->ast !== null) {
                $parsedFiles[$uri] = ['ast' => $otherResult->ast, 'source' => $item->text];
            }
        }

        // Enrich the bound-check hierarchy with filesystem-indexed files the
        // ParsedDocumentCacheWarmer has already parsed. Without this, the workspace
        // pass only sees open buffers — so `new Box<Tag>(…)` in an open file dependent
        // on a Tag class that's on disk but not open fires a spurious
        // "concrete type is not in the source set" diagnostic.
        //
        // A file is admitted only when it is the NEAREST declarer (to the document
        // being linted) of a class it defines. That keeps each FQN's ancestry
        // single-sourced even when the workspace has duplicate FQNs across
        // packages / fixture trees (`pathFor` resolves the proximity winner;
        // open buffers already in $parsedFiles win and exclude their on-disk
        // copies). Files that declare no class — pure usage sites — contribute
        // nothing to the ancestry and are skipped.
        $hierarchyAsts = [];
        foreach ($this->fqnIndex->indexedFilesystemPaths() as $path) {
            $uri = 'file://' . $path;
            if (isset($parsedFiles[$uri])) {
                continue;
            }
            $peek = $this->cache->peek($uri);
            if ($peek === null || $peek->ast === null) {
                continue;
            }
            foreach (WorkspaceAnalyzer::classFqnsIn($peek->ast) as $fqn) {
                if ($this->fqnIndex->pathFor($fqn, $currentUri) === $path) {
                    $hierarchyAsts[$uri] = $peek->ast;
                    break;
                }
            }
        }

        $workspaceByUri = $parsedFiles === []
            ? []
            : $this->workspaceAnalyzer->analyze($parsedFiles, $hierarchyAsts);

        $result = [];
        foreach ($perFileByUri as $uri => $perFile) {
            $meta = $docMeta[$uri] ?? ['version' => 0, 'source' => ''];
            $workspaceDiagnostics = $this->toLspClamped(
                $workspaceByUri[$uri] ?? [],
                $uri,
                $meta['version'],
                $meta['source'],
            );
            $result[$uri] = array_merge($perFile, $workspaceDiagnostics);
        }

        return $result;
    }

    /**
     * Conservative null-dereference diagnostics (`xphp.null-deref`) for one
     * document. Asks the type-inference resolver for plain-`->` accesses on a
     * statically nullable chained receiver, then maps each site's STRIPPED-source
     * byte offsets back to the original buffer (via the parse's `ByteOffsetMap`)
     * and on to an LSP range (via the cached `PositionMap`).
     *
     * No-op when no resolver is wired (pull-mode unit contexts) or the document
     * didn't parse -- the rest of the pipeline is unaffected.
     *
     * @return list<Diagnostic>
     */
    private function collectNullDerefDiagnostics(string $uri, int $version, string $source, ParseResult $result): array
    {
        if ($this->genericResolver === null || $result->ast === null) {
            return [];
        }
        $sites = $this->genericResolver->findNullDerefSites($uri, $version, $source);
        if ($sites === []) {
            return [];
        }
        $positionMap = $this->cache->positionMap($uri, $version, $source);
        $out = [];
        foreach ($sites as $site) {
            $origStart = $result->byteOffsetMap->toOriginal($site->strippedStart);
            // end offset is inclusive in the AST; +1 makes the LSP range
            // half-open over the last character of the member name.
            $origEnd = $result->byteOffsetMap->toOriginal($site->strippedEnd + 1);
            if ($origStart < 0 || $origEnd < $origStart) {
                continue;
            }
            [$startLine, $startChar] = $positionMap->offsetToPosition($origStart);
            [$endLine, $endChar] = $positionMap->offsetToPosition($origEnd);
            $out[] = new Diagnostic(
                startLine: $startLine,
                startCharacter: $startChar,
                endLine: $endLine,
                endCharacter: $endChar,
                message: sprintf(
                    'Accessing "%s" on a possibly-null value (%s). Use `?->` or guard against null first.',
                    $site->member,
                    $site->receiverType,
                ),
                code: DiagnosticCode::NullDeref,
                severity: DiagnosticSeverity::Warning,
            );
        }
        return $out;
    }

    /**
     * Translate internal diagnostics to LSP-wire diagnostics, clamping every
     * range into the document's bounds first. The server must never emit a range
     * outside the buffer it analyzed: a strict LSP annotator (PhpStorm) throws
     * `Range must be inside element being annotated` when an EOF-anchored
     * diagnostic lands one character past end-of-document mid-edit. Uses the
     * cached PositionMap so we don't re-scan the source.
     *
     * @param  list<Diagnostic> $diags
     * @return list<LspDiagnostic>
     */
    private function toLspClamped(array $diags, string $uri, int $version, string $source): array
    {
        if ($diags === []) {
            return [];
        }
        $positionMap = $this->cache->positionMap($uri, $version, $source);
        $out = [];
        foreach ($diags as $d) {
            $lsp = DiagnosticTranslator::toLsp($d);
            [$sl, $sc, $el, $ec] = $positionMap->clampRange(
                $lsp->range->start->line,
                $lsp->range->start->character,
                $lsp->range->end->line,
                $lsp->range->end->character,
            );
            $lsp->range = new Range(new Position($sl, $sc), new Position($el, $ec));
            $out[] = $lsp;
        }
        return $out;
    }

    /**
     * Push `textDocument/publishDiagnostics` for every open document OTHER than
     * the one being linted whose diagnostics changed since we last published
     * them. No-op when no client handle is wired (pull-mode / unit contexts).
     *
     * @param array<string, list<LspDiagnostic>> $byUri
     */
    private function broadcastDependents(string $currentUri, array $byUri): void
    {
        if ($this->clientApi === null) {
            return;
        }
        foreach ($byUri as $uri => $diagnostics) {
            if ($uri === $currentUri) {
                // The engine publishes the linted document itself.
                continue;
            }
            if (!$this->workspace->has($uri)) {
                continue;
            }
            $signature = self::signature($diagnostics);
            if (($this->lastBroadcast[$uri] ?? null) === $signature) {
                continue;
            }
            $this->lastBroadcast[$uri] = $signature;
            $this->clientApi->diagnostics()->publishDiagnostics(
                $uri,
                $this->workspace->get($uri)->version,
                $diagnostics,
            );
        }
    }

    /**
     * Order-sensitive fingerprint of a diagnostic list, used to skip redundant
     * re-publishes.
     *
     * @param list<LspDiagnostic> $diagnostics
     */
    private static function signature(array $diagnostics): string
    {
        $parts = [];
        foreach ($diagnostics as $d) {
            $parts[] = implode(':', [
                $d->range->start->line,
                $d->range->start->character,
                $d->range->end->line,
                $d->range->end->character,
                (string) ($d->code ?? ''),
                $d->message,
            ]);
        }
        return implode('|', $parts);
    }
}
