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
     * @return array<string, list<LspDiagnostic>>
     */
    public function run(): array;
}
