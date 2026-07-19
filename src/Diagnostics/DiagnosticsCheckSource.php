<?php

declare(strict_types=1);

namespace XPHP\Lsp\Diagnostics;

use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;

/**
 * Produces authoritative (whole-project compiler `check()`) diagnostics keyed by
 * file URI. Implemented by {@see CompilerCheckRunner}; exists as a seam so
 * {@see AuthoritativeDiagnosticsListener} can be unit-tested with a canned source
 * instead of driving the real compiler.
 */
interface DiagnosticsCheckSource
{
    /**
     * @param ?string $fromPath absolute path of the file that triggered the run
     *        (the just-saved document). When given, the source set is resolved
     *        from the nearest `xphp.json` walking up from that file's directory —
     *        so multi-root / mis-rooted workspaces still scope to the file's own
     *        project. Null falls back to the configured workspace root.
     * @return array<string, list<LspDiagnostic>>
     */
    public function run(?string $fromPath = null): array;
}
