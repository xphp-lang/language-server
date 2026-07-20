<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Handler\ClosureTypePositionDetector;

/**
 * Pins {@see ClosureTypePositionDetector::detect} -- whether a cursor sits at a
 * type token inside a `Closure(int $x, string $y): bool` signature type.
 */
final class ClosureTypePositionDetectorTest extends TestCase
{
    private static function at(string $source): ?array
    {
        return ClosureTypePositionDetector::detect($source, strlen($source));
    }

    /** Cursor at the marker `|` (removed from the source), i.e. mid-buffer. */
    private static function atCaret(string $sourceWithCaret): ?array
    {
        $offset = strpos($sourceWithCaret, '|');
        self::assertNotFalse($offset, 'fixture must contain a | caret');

        return ClosureTypePositionDetector::detect(str_replace('|', '', $sourceWithCaret), $offset);
    }

    public function testFirstParamPositionRightAfterOpenParen(): void
    {
        self::assertSame(['prefix' => ''], self::at('function h(Closure('));
    }

    public function testFirstParamWithPartialPrefix(): void
    {
        self::assertSame(['prefix' => 'Str'], self::at('function h(Closure(Str'));
    }

    public function testNextParamAfterComma(): void
    {
        self::assertSame(['prefix' => ''], self::at('function h(Closure(int $x, '));
    }

    public function testNextParamAfterCommaWithPrefix(): void
    {
        self::assertSame(['prefix' => 'boo'], self::at('function h(Closure(int $x, boo'));
    }

    public function testReturnTypePositionAfterColon(): void
    {
        self::assertSame(['prefix' => ''], self::at('function h(Closure(int $x): '));
    }

    public function testReturnTypePositionWithPrefix(): void
    {
        self::assertSame(['prefix' => 'bo'], self::at('function h(Closure(int $x): bo'));
    }

    public function testCommaInsideNestedGenericIsNotASeparator(): void
    {
        // The comma sits inside `Map<K, V>` at angle-depth 1, so the cursor is
        // still the FIRST closure param slot -- prefix empty, position valid.
        self::assertSame(['prefix' => ''], self::at('function h(Closure(Map<K, '));
    }

    public function testAfterNestedGenericParamComma(): void
    {
        self::assertSame(['prefix' => ''], self::at('function h(Closure(Map<K, V> $m, '));
    }

    public function testParamNamePositionIsNotAType(): void
    {
        // `Closure(int |` -- cursor is where the `$name` goes, not a type.
        self::assertNull(self::at('function h(Closure(int '));
    }

    public function testNewClosureConstructorIsNotASignature(): void
    {
        self::assertNull(self::at('$c = new Closure('));
    }

    public function testStaticCallOnClosureIsNotASignature(): void
    {
        self::assertNull(self::at('Closure::fromCallable('));
    }

    public function testFullyQualifiedClosureIsNotASignature(): void
    {
        self::assertNull(self::at('function h(\\Closure('));
    }

    public function testDoubleColonReturnGuardIsNotReturnType(): void
    {
        // A `::` (static access) must not be read as the return `:` position.
        self::assertNull(self::at('Foo::'));
    }

    public function testPlainCodeIsNotAClosureType(): void
    {
        self::assertNull(self::at('$x = foo(1, '));
    }

    public function testCaseInsensitiveClosureKeyword(): void
    {
        self::assertSame(['prefix' => ''], self::at('function h(closure('));
    }

    // --- whitespace handling around the anchors ---------------------------

    public function testWhitespaceAfterOpenParenStillFirstParam(): void
    {
        self::assertSame(['prefix' => ''], self::at('function h(Closure(   '));
    }

    public function testWhitespaceBeforeCommaAndCursor(): void
    {
        self::assertSame(['prefix' => ''], self::at('function h(Closure(int $x ,   '));
    }

    public function testWhitespaceAroundReturnColon(): void
    {
        self::assertSame(['prefix' => ''], self::at('function h(Closure(int $x)   :   '));
    }

    public function testWhitespaceBetweenClosureKeywordAndParen(): void
    {
        self::assertSame(['prefix' => ''], self::at('function h(Closure   ('));
    }

    public function testWhitespaceBetweenNewAndClosureStillGuarded(): void
    {
        self::assertNull(self::at('$c = new   Closure('));
    }

    // --- identifier prefixes with `_` and namespace separators ------------

    public function testUnderscoredPrefixIsCaptured(): void
    {
        self::assertSame(['prefix' => 'My_Type'], self::at('function h(Closure(My_Type'));
    }

    public function testNamespacedPrefixIncludesBackslash(): void
    {
        self::assertSame(['prefix' => 'App\\Fo'], self::at('function h(Closure(App\\Fo'));
    }

    public function testUnderscoredClosureLookalikeNameIsNotAnOpener(): void
    {
        // `My_Closure(` -- the opener word is `My_Closure`, not `Closure`.
        self::assertNull(self::at('function h(My_Closure('));
    }

    // --- nested-bracket depth in the enclosing-paren / matching-paren walk -

    public function testNestedParensInParamListDoNotBreakCommaAnchor(): void
    {
        // A parenthesised DNF param `(int|string)`: the inner `()` is balanced,
        // so the trailing comma still belongs to the Closure param list.
        self::assertSame(['prefix' => ''], self::at('function h(Closure((int|string) $x, '));
    }

    public function testNestedParensBeforeReturnColon(): void
    {
        // The `)` closing the param list must match past a balanced inner `()`.
        self::assertSame(['prefix' => ''], self::at('function h(Closure((int|string) $x): '));
    }

    public function testNestedSquareBracketDoesNotBreakCommaAnchor(): void
    {
        // Array-sugar `Item[]` inside the param list.
        self::assertSame(['prefix' => ''], self::at('function h(Closure(Item[] $x, '));
    }

    public function testStatementBoundaryStopsTheParenWalk(): void
    {
        // A `,` not inside any Closure( group (a plain call after a `;`) is not a
        // closure type position; the backward walk must stop at the `;`.
        self::assertNull(self::at('$a = foo(); bar(1, '));
    }

    public function testAbsoluteWindowsStylePrefixIsStillAType(): void
    {
        // Exercises the drive-letter arm indirectly: a bare identifier prefix
        // after the opener resolves as a first-param type position.
        self::assertSame(['prefix' => 'Foo'], self::at('function h(Closure(Foo'));
    }

    public function testCursorBeforeAnyAnchorReturnsNull(): void
    {
        // Only whitespace / nothing to the left -> no anchor.
        self::assertNull(ClosureTypePositionDetector::detect('   ', 3));
        self::assertNull(ClosureTypePositionDetector::detect('', 0));
    }

    public function testOffsetOutOfRangeReturnsNull(): void
    {
        self::assertNull(ClosureTypePositionDetector::detect('function h(Closure(', 999));
        self::assertNull(ClosureTypePositionDetector::detect('function h(Closure(', -1));
    }

    public function testReturnColonImmediatelyAfterCloseParen(): void
    {
        // No whitespace between `)` and `:` -- the `)` scan must still fire.
        self::assertSame(['prefix' => 'in'], self::at('function h(Closure(int $x):in'));
    }

    // --- index-0 boundary: opener / anchor at the very start of the buffer -
    // These force the backward scans to terminate at index 0, exercising the
    // `>= 0` loop guards and the identifier capture that spans to offset 0.

    public function testOpenerAtBufferStartFirstParam(): void
    {
        self::assertSame(['prefix' => ''], self::at('Closure('));
    }

    public function testOpenerAtBufferStartWithPrefix(): void
    {
        self::assertSame(['prefix' => 'Fo'], self::at('Closure(Fo'));
    }

    public function testOpenerAtBufferStartNextParam(): void
    {
        self::assertSame(['prefix' => ''], self::at('Closure(int $x, '));
    }

    public function testOpenerAtBufferStartReturnPosition(): void
    {
        self::assertSame(['prefix' => ''], self::at('Closure(int $x): '));
    }

    public function testBareOpenParenAtStartIsNotAnOpener(): void
    {
        // `(` at offset 0 with no preceding `Closure` word: the identifier
        // capture spans to an empty word -> not a signature.
        self::assertNull(self::at('('));
    }

    public function testCommaWalkReachesBufferStartWithoutAParen(): void
    {
        // A top-level comma with no enclosing `(` back to offset 0 -> the
        // enclosing-paren walk returns null (not a closure position).
        self::assertNull(self::at('int, '));
    }

    public function testClosureKeywordAtStartWithNoParenIsNotAType(): void
    {
        // `Closure` at the start followed by a type-name stem but no `(` anchor.
        self::assertNull(self::at('Closure Fo'));
    }

    // --- mid-buffer cursors: content exists to the RIGHT of the cursor -----
    // The `at()` helper always parks the cursor at end-of-string, which makes
    // the prefix-length arithmetic (`$offset - $stemStart`) look equivalent.
    // A caret mid-buffer, with trailing bytes, distinguishes it.

    public function testMidBufferPrefixStopsAtCursorNotEndOfLine(): void
    {
        self::assertSame(['prefix' => 'Foo'], self::atCaret('function h(Closure(Foo| $x): bool {}'));
    }

    public function testMidBufferEmptyPrefixWithTrailingType(): void
    {
        self::assertSame(['prefix' => ''], self::atCaret('function h(Closure(|int $x): bool {}'));
    }

    public function testMidBufferNextParamPrefix(): void
    {
        self::assertSame(['prefix' => 'St'], self::atCaret('function h(Closure(int $x, St| $y): bool {}'));
    }

    public function testMidBufferReturnPositionPrefix(): void
    {
        self::assertSame(['prefix' => 'bo'], self::atCaret('function h(Closure(int $x): bo|ol {}'));
    }

    public function testMidBufferParamNamePositionIsNotAType(): void
    {
        // Cursor at the `$x` name position, with a return type following.
        self::assertNull(self::atCaret('function h(Closure(int |$x): bool {}'));
    }

    public function testMidBufferNamespacedPrefixWithTrailingContent(): void
    {
        self::assertSame(['prefix' => 'App\\Ma'], self::atCaret('function h(Closure(App\\Ma|p $x): void {}'));
    }
}
