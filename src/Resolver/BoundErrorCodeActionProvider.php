<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\CodeActionKind;
use Phpactor\LanguageServerProtocol\Diagnostic;
use Phpactor\LanguageServerProtocol\OptionalVersionedTextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentEdit;
use Phpactor\LanguageServerProtocol\TextEdit;
use Phpactor\LanguageServerProtocol\WorkspaceEdit;
use XPHP\Lsp\Analyzer\DiagnosticCode;

/**
 * Quick-fixes for generic bound violations (`xphp.bound`).
 *
 * The diagnostic carries a structured `data` payload computed by the
 * WorkspaceAnalyzer at the point of the violation (which type-param/bound was
 * violated, the offending concrete type, the source range of the offending
 * type-argument, workspace types that DO satisfy the bound, and -- when the
 * concrete type is an editable open class -- where to add an `implements`
 * clause). This provider is purely data-driven: it reads those facts and
 * assembles the WorkspaceEdits, with no need to re-derive anything from the
 * source or message text.
 *
 * Two fixes:
 *   - "Change type argument to <Candidate>" -- one per bound-satisfying
 *     workspace type, replacing the offending type-argument. Works even when
 *     the concrete type is a scalar.
 *   - "Add implements \Bound to <Concrete>" -- a cross-file edit on the
 *     offending concrete class (only when it's an editable open class).
 */
final class BoundErrorCodeActionProvider
{
    /**
     * @param list<Diagnostic> $diagnostics
     * @return list<CodeAction>
     */
    public function actionsFor(string $uri, int $version, string $source, array $diagnostics): array
    {
        $actions = [];
        foreach ($diagnostics as $diagnostic) {
            if (self::diagnosticCode($diagnostic) !== DiagnosticCode::BoundViolation->value) {
                continue;
            }
            $data = $diagnostic->data ?? null;
            if (!is_array($data) || ($data['kind'] ?? null) !== 'bound') {
                continue;
            }
            $actions = array_merge(
                $actions,
                $this->swapActions($uri, $version, $diagnostic, $data),
                $this->implementActions($diagnostic, $data),
            );
        }
        return $actions;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<CodeAction>
     */
    private function swapActions(string $uri, int $version, Diagnostic $diagnostic, array $data): array
    {
        $range = self::rangeFrom($data['typeArgRange'] ?? null);
        $candidates = $data['candidates'] ?? [];
        if ($range === null || !is_array($candidates)) {
            return [];
        }
        $actions = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $actions[] = new CodeAction(
                title: sprintf('Change type argument to %s', $candidate),
                kind: CodeActionKind::QUICK_FIX,
                diagnostics: [$diagnostic],
                edit: new WorkspaceEdit(null, [
                    new TextDocumentEdit(
                        new OptionalVersionedTextDocumentIdentifier($uri, $version),
                        [new TextEdit($range, $candidate)],
                    ),
                ]),
            );
        }
        return $actions;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<CodeAction>
     */
    private function implementActions(Diagnostic $diagnostic, array $data): array
    {
        $insert = $data['implementsInsert'] ?? null;
        $bound = $data['bound'] ?? null;
        $concrete = $data['concrete'] ?? null;
        if (!is_array($insert) || !is_string($bound) || !is_string($concrete)) {
            return [];
        }
        $uri = $insert['uri'] ?? null;
        $line = $insert['line'] ?? null;
        $character = $insert['character'] ?? null;
        $newText = $insert['newText'] ?? null;
        if (!is_string($uri) || !is_int($line) || !is_int($character) || !is_string($newText)) {
            return [];
        }
        $point = new Position($line, $character);
        $concreteShort = strrpos($concrete, '\\') !== false
            ? substr($concrete, strrpos($concrete, '\\') + 1)
            : $concrete;
        return [
            new CodeAction(
                title: sprintf('Add implements \\%s to %s', $bound, $concreteShort),
                kind: CodeActionKind::QUICK_FIX,
                diagnostics: [$diagnostic],
                edit: new WorkspaceEdit(null, [
                    new TextDocumentEdit(
                        new OptionalVersionedTextDocumentIdentifier($uri, null),
                        [new TextEdit(new Range($point, $point), $newText)],
                    ),
                ]),
            ),
        ];
    }

    /**
     * @param mixed $range
     */
    private static function rangeFrom($range): ?Range
    {
        if (!is_array($range)) {
            return null;
        }
        foreach (['startLine', 'startCharacter', 'endLine', 'endCharacter'] as $key) {
            if (!is_int($range[$key] ?? null)) {
                return null;
            }
        }
        return new Range(
            new Position($range['startLine'], $range['startCharacter']),
            new Position($range['endLine'], $range['endCharacter']),
        );
    }

    /**
     * LSP carries diagnostic codes as either string OR int; our analyzer emits
     * the string form.
     */
    private static function diagnosticCode(Diagnostic $diagnostic): string
    {
        return is_string($diagnostic->code) || is_int($diagnostic->code)
            ? (string) $diagnostic->code
            : '';
    }
}
