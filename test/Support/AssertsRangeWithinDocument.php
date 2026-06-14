<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Support;

use XPHP\Lsp\PositionMap;

/**
 * Shared invariant: an LSP range emitted by the server must sit inside the
 * document it was computed against. A strict client annotator (PhpStorm's LSP
 * layer) throws `Range must be inside element being annotated` when a range
 * lands past end-of-document, so this is a hard correctness property — not a
 * nicety. Used by the diagnostics range tests today; reusable by the other
 * range-emitting handlers (semantic tokens, folding, inlay hints, …) when the
 * same invariant is applied to them.
 */
trait AssertsRangeWithinDocument
{
    /**
     * Assert that the LSP range [(startLine,startChar)..(endLine,endChar)],
     * with characters in UTF-16 code units, is within `$source`'s bounds:
     * lines within [0, lastLine], characters within [0, that line's UTF-16
     * length], and start not after end.
     */
    protected static function assertRangeWithinDocument(
        string $source,
        int $startLine,
        int $startChar,
        int $endLine,
        int $endChar,
        string $message = '',
    ): void {
        $lines = explode("\n", $source);
        $lastLine = count($lines) - 1;
        $prefix = $message !== '' ? $message . ': ' : '';

        self::assertGreaterThanOrEqual(0, $startLine, $prefix . 'startLine >= 0');
        self::assertGreaterThanOrEqual(0, $startChar, $prefix . 'startChar >= 0');
        self::assertLessThanOrEqual($lastLine, $endLine, $prefix . 'endLine within document');
        self::assertGreaterThanOrEqual($startLine, $endLine, $prefix . 'endLine >= startLine');

        // The `\n` terminator is not part of the line's renderable content, so
        // each line's max valid character is its UTF-16 content length.
        $startLineLen = PositionMap::lengthInUtf16($lines[$startLine] ?? '');
        $endLineLen = PositionMap::lengthInUtf16($lines[$endLine] ?? '');
        self::assertLessThanOrEqual($startLineLen, $startChar, $prefix . 'startChar within its line');
        self::assertLessThanOrEqual($endLineLen, $endChar, $prefix . 'endChar within its line');

        if ($startLine === $endLine) {
            self::assertGreaterThanOrEqual($startChar, $endChar, $prefix . 'endChar >= startChar on same line');
        }
    }
}
