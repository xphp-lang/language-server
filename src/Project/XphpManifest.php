<?php

declare(strict_types=1);

namespace XPHP\Lsp\Project;

use XPHP\Config\Manifest;
use XPHP\Config\ManifestParser;

/**
 * Defensive, never-throwing LSP façade over an xphp 0.3.0 `xphp.json` project
 * manifest.
 *
 * The compiler owns the canonical schema + parser ({@see ManifestParser},
 * `string -> Manifest`, which throws on a malformed document); this reader wraps
 * it so the language server can locate, read, and resolve a manifest without any
 * failure mode reaching the caller. An absent, unreadable, or malformed manifest
 * yields `null` -- the signal for the resolver (WI-13) to fall back to the single
 * `rootPath` from `InitializeParams`. The server must never hard-fail on a bad
 * manifest.
 *
 * The manifest's own paths are written relative to its directory; the accessors
 * here resolve them to absolute filesystem paths against {@see $baseDir} so the
 * FQN index can walk the source roots and exclude the build-output / cache dirs.
 * The compiler's schema is `sources` (own `.xphp` roots, default `["."]`),
 * `include` (other packages / globs), `target` (build output), `cache`
 * (generated-class cache) -- unknown keys are ignored by the underlying parser.
 */
final readonly class XphpManifest
{
    public const FILENAME = 'xphp.json';

    /**
     * @param string $path    absolute path to the `xphp.json` this was parsed from
     * @param string $baseDir absolute directory containing the manifest
     */
    private function __construct(
        public string $path,
        public string $baseDir,
        private Manifest $manifest,
    ) {
    }

    /**
     * Load a specific `xphp.json`. Returns null -- never throws -- when the file
     * is missing, unreadable, or malformed (invalid JSON, wrong-typed field, or a
     * top-level non-object), so a broken manifest degrades to the single-root
     * fallback rather than breaking the server.
     */
    public static function fromFile(string $path): ?self
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }
        try {
            $manifest = (new ManifestParser())->parse($json, $path);
        } catch (\Throwable) {
            return null;
        }
        $real = realpath($path);
        $abs = $real !== false ? $real : $path;

        return new self($abs, \dirname($abs), $manifest);
    }

    /**
     * Walk up from `$startDir` (or the directory of a file path) looking for an
     * `xphp.json`, returning its path or null. Stops at the filesystem root.
     */
    public static function locate(string $startDir): ?string
    {
        $dir = is_dir($startDir) ? $startDir : \dirname($startDir);
        $real = realpath($dir);
        $dir = $real !== false ? $real : $dir;

        $previous = null;
        while ($dir !== $previous) {
            $candidate = $dir . \DIRECTORY_SEPARATOR . self::FILENAME;
            if (is_file($candidate)) {
                return $candidate;
            }
            $previous = $dir;
            $dir = \dirname($dir);
        }

        return null;
    }

    /**
     * Discover a manifest for a workspace. An explicit `$config` (the `--config`
     * override -- a manifest file, or a directory containing one) takes
     * precedence; otherwise auto-detect by walking up from `$startDir`. Returns
     * null when none is found or the chosen one is malformed.
     */
    public static function discover(string $startDir, ?string $config = null): ?self
    {
        if ($config !== null && $config !== '') {
            $path = is_dir($config)
                ? rtrim($config, '/\\') . \DIRECTORY_SEPARATOR . self::FILENAME
                : $config;

            return self::fromFile($path);
        }
        $located = self::locate($startDir);

        return $located !== null ? self::fromFile($located) : null;
    }

    /**
     * Absolute `.xphp` source-root directories to index. Defaults to `[$baseDir]`
     * (the parser substitutes `["."]` when the key is absent).
     *
     * @return list<string>
     */
    public function sourceRoots(): array
    {
        $roots = [];
        foreach ($this->manifest->sources as $relative) {
            $roots[] = $this->resolveRelative($relative);
        }

        return $roots;
    }

    /**
     * Raw (manifest-relative) `include` entries -- other packages to pull in, each
     * a directory or a glob (`*`/`?`/`[…]`, or `**` for recursive discovery).
     * Glob resolution is the resolver's job (WI-13); this reader only surfaces the
     * declared entries.
     *
     * @return list<string>
     */
    public function includes(): array
    {
        return $this->manifest->include;
    }

    /**
     * Absolute build-output directory (the manifest's `target`), to exclude from
     * indexing, or null when unset.
     */
    public function outputDir(): ?string
    {
        return $this->manifest->target !== null
            ? $this->resolveRelative($this->manifest->target)
            : null;
    }

    /**
     * Absolute generated-class cache directory (the manifest's `cache`), to
     * exclude from indexing, or null when unset.
     */
    public function cacheDir(): ?string
    {
        return $this->manifest->cache !== null
            ? $this->resolveRelative($this->manifest->cache)
            : null;
    }

    /**
     * Resolve a manifest-relative path onto {@see $baseDir}, lexically (no
     * filesystem access, so a not-yet-created output dir still resolves). `.` /
     * empty maps to the base dir itself; an already-absolute path is returned
     * unchanged.
     */
    private function resolveRelative(string $relative): string
    {
        $relative = trim($relative);
        if ($relative === '' || $relative === '.') {
            return $this->baseDir;
        }
        if (self::isAbsolute($relative)) {
            return $relative;
        }

        return rtrim($this->baseDir, '/\\') . \DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }
}
