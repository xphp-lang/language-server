<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

/**
 * Framework-neutral diagnostic with positions in LSP shape (0-based line + character).
 *
 * Handlers translate this to phpactor/language-server-protocol's Diagnostic at the
 * wire boundary. Keeping the analyzer output framework-free means the test suite
 * can assert against plain PHP objects without spinning up an LSP transport.
 *
 * `$code` is required and typed: every diagnostic declares its category up
 * front so editors / users can pattern-match by code (e.g. "downgrade
 * xphp.parse.internal to info"). The previous freeform `string $code = ''`
 * encouraged inconsistency between catch sites.
 *
 * `$data` is an optional structured payload carried through to the LSP
 * `Diagnostic.data` field. Clients round-trip it back on
 * `textDocument/codeAction`, so a code-action provider can build a quick-fix
 * from facts the analyzer already computed (e.g. the bound-violation fix-its
 * read the offending type-arg range + candidate types from here) without
 * re-deriving them from the message text.
 */
final readonly class Diagnostic
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public int $startLine,
        public int $startCharacter,
        public int $endLine,
        public int $endCharacter,
        public string $message,
        public DiagnosticCode $code,
        public DiagnosticSeverity $severity = DiagnosticSeverity::Error,
        public ?array $data = null,
    ) {
    }
}
