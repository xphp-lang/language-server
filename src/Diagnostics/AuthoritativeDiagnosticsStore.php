<?php

declare(strict_types=1);

namespace XPHP\Lsp\Diagnostics;

use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;

/**
 * Holds the most recent *authoritative* diagnostics — the ones produced by the
 * upstream compiler's own {@see \XPHP\Transpiler\Monomorphize\Compiler::check()}
 * over the whole source set, keyed by file URI.
 *
 * Why a shared store rather than a second publisher: `publishDiagnostics` is a
 * full replace per URI, so if both the fast tolerant provider (engine-driven,
 * per keystroke) and the on-save authoritative pass published the same URI they
 * would clobber each other. Instead the authoritative pass writes here on save,
 * and {@see XphpDiagnosticsProvider} merges these in whenever the engine
 * publishes an open document — so a single publish carries both tiers. Files
 * that are NOT open are published directly by
 * {@see AuthoritativeDiagnosticsListener} (the engine never touches those).
 *
 * The stored ranges are computed against the file ON DISK at save time. After a
 * subsequent edit the buffer diverges from disk, so these become stale until the
 * next save refresh — acceptable and self-correcting (the disk is what the
 * compiler validated).
 */
final class AuthoritativeDiagnosticsStore
{
    /** @var array<string, list<LspDiagnostic>> */
    private array $byUri = [];

    /**
     * Replace the entire store with a fresh workspace-wide pass. Stale-clearing
     * of files that dropped out is the listener's responsibility (it tracks what
     * it published), so this is a plain swap.
     *
     * @param array<string, list<LspDiagnostic>> $byUri
     */
    public function replaceAll(array $byUri): void
    {
        $this->byUri = $byUri;
    }

    /**
     * @return list<LspDiagnostic>
     */
    public function get(string $uri): array
    {
        return $this->byUri[$uri] ?? [];
    }
}
