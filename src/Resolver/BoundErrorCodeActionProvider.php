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
 *   - "Change type argument to <Candidate>" -- one per workspace type that
 *     satisfies the WHOLE bound (every leaf of an intersection, any leaf of a
 *     union), replacing the offending type-argument. Works even when the
 *     concrete type is a scalar.
 *   - "Add implements \Leaf to <Concrete>" -- one cross-file edit per bound
 *     leaf the concrete class is missing (only when it's an editable open
 *     class). Suppressed for union bounds, where implementing any single leaf
 *     would satisfy it but choosing one is ambiguous.
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
        // One implement fix per MISSING leaf. The analyzer emits an entry per
        // leaf the concrete class doesn't yet implement (and emits none for a
        // union bound, where implementing any single leaf would suffice but
        // picking one is ambiguous, or for a scalar concrete).
        $inserts = $data['implementsInserts'] ?? null;
        $concrete = $data['concrete'] ?? null;
        if (!is_array($inserts) || !is_string($concrete)) {
            return [];
        }
        $concreteShort = strrpos($concrete, '\\') !== false
            ? substr($concrete, strrpos($concrete, '\\') + 1)
            : $concrete;
        $actions = [];
        foreach ($inserts as $insert) {
            if (!is_array($insert)) {
                continue;
            }
            $leaf = $insert['leaf'] ?? null;
            $uri = $insert['uri'] ?? null;
            $line = $insert['line'] ?? null;
            $character = $insert['character'] ?? null;
            $newText = $insert['newText'] ?? null;
            if (!is_string($leaf) || !is_string($uri) || !is_int($line)
                || !is_int($character) || !is_string($newText)
            ) {
                continue;
            }
            $point = new Position($line, $character);
            $actions[] = new CodeAction(
                title: sprintf('Add implements \\%s to %s', $leaf, $concreteShort),
                kind: CodeActionKind::QUICK_FIX,
                diagnostics: [$diagnostic],
                edit: new WorkspaceEdit(null, [
                    new TextDocumentEdit(
                        new OptionalVersionedTextDocumentIdentifier($uri, null),
                        [new TextEdit(new Range($point, $point), $newText)],
                    ),
                ]),
            );
        }
        return $actions;
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
