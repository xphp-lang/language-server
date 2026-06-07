<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Handler\TurbofishScanner;

final class TurbofishScannerTest extends TestCase
{
    // --- detectCursorInClause -------------------------------------------

    public function testDetectsCursorRightAfterOpener(): void
    {
        $source = 'Util::identity::<';
        $hit = TurbofishScanner::detectCursorInClause($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'identity', 'slot' => 0], $hit);
    }

    public function testDetectsEmptyAllDefaultsClause(): void
    {
        // `Foo::<>` -- cursor between `<` and `>` of the empty clause.
        $source = 'new Box::<>';
        $hit = TurbofishScanner::detectCursorInClause($source, strpos($source, '<') + 1);
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 0], $hit);
    }

    public function testReadsContainerLeftOfDoubleColon(): void
    {
        $source = 'Map::<K, ';
        $hit = TurbofishScanner::detectCursorInClause($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Map', 'slot' => 1], $hit);
    }

    public function testToleratesWhitespaceBetweenNameAndDoubleColon(): void
    {
        // The `::` opener guard skips whitespace before `::`.
        $source = 'Box ::<';
        $hit = TurbofishScanner::detectCursorInClause($source, strlen($source));
        self::assertSame('Box', $hit['containerName']);
    }

    public function testRejectsBareAngleWithoutDoubleColon(): void
    {
        $source = 'Box<';
        self::assertNull(TurbofishScanner::detectCursorInClause($source, strlen($source)));
    }

    public function testRejectsSingleColonBeforeAngle(): void
    {
        // A single `:` (not `::`) is not a turbofish opener.
        $source = 'Box:<';
        self::assertNull(TurbofishScanner::detectCursorInClause($source, strlen($source)));
    }

    public function testRejectsDoubleColonWithoutReceiverName(): void
    {
        $source = '::<';
        self::assertNull(TurbofishScanner::detectCursorInClause($source, strlen($source)));
    }

    public function testRejectsNegativeOffset(): void
    {
        self::assertNull(TurbofishScanner::detectCursorInClause('Box::<', -1));
    }

    public function testRejectsOffsetPastSourceLength(): void
    {
        self::assertNull(TurbofishScanner::detectCursorInClause('Box::<', 999));
    }

    public function testDetectsAtSlotAfterTwoCommas(): void
    {
        $source = 'Map::<A, B, ';
        $hit = TurbofishScanner::detectCursorInClause($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Map', 'slot' => 2], $hit);
    }

    public function testNestedBareInsideTurbofishCapturesInnerContainer(): void
    {
        // `Box::<List<Pla` -- inner bare container is `List`, slot 0; the outer
        // turbofish (`Box::<`) anchors the whole thing.
        $source = 'Box::<List<Pla';
        $hit = TurbofishScanner::detectCursorInClause($source, strlen($source));
        self::assertSame(['prefix' => 'Pla', 'containerName' => 'List', 'slot' => 0], $hit);
    }

    public function testNestedBareWithoutOuterTurbofishIsRejected(): void
    {
        // `Box<List<Pla` -- no `::` anywhere; not a turbofish at all.
        $source = 'Box<List<Pla';
        self::assertNull(TurbofishScanner::detectCursorInClause($source, strlen($source)));
    }

    public function testWhitespaceAfterDoubleColonBeforeAngle(): void
    {
        // The opener tolerates whitespace between `::` and `<`.
        $source = 'Box:: <';
        $hit = TurbofishScanner::detectCursorInClause($source, strlen($source));
        self::assertSame('Box', $hit['containerName']);
    }

    public function testBreaksOnSemicolonInsideWalk(): void
    {
        // A `;` before reaching any `<` breaks the clause context.
        $source = '$y; Box';
        self::assertNull(TurbofishScanner::detectCursorInClause($source, strlen($source)));
    }

    // --- clauseAfter -----------------------------------------------------

    public function testClauseAfterFindsTurbofishRange(): void
    {
        $source = 'Box::<Plastic>';
        $nameEnd = strpos($source, 'Box') + 2; // last byte of `Box`
        $range = TurbofishScanner::clauseAfter($source, $nameEnd);
        self::assertSame(strpos($source, '<'), $range['openPos']);
        self::assertSame(strpos($source, '>'), $range['closePos']);
    }

    public function testClauseAfterToleratesWhitespaceAroundDoubleColon(): void
    {
        $source = 'Box :: <int>';
        $nameEnd = 2; // last byte of `Box`
        $range = TurbofishScanner::clauseAfter($source, $nameEnd);
        self::assertSame(strpos($source, '<'), $range['openPos']);
        self::assertSame(strpos($source, '>'), $range['closePos']);
    }

    public function testClauseAfterRejectsBareAngle(): void
    {
        // No `::` -- a bare `Box<int>` is not a call-site clause.
        $source = 'Box<int>';
        self::assertNull(TurbofishScanner::clauseAfter($source, 2));
    }

    public function testClauseAfterRejectsUnterminatedClause(): void
    {
        $source = 'Box::<int';
        self::assertNull(TurbofishScanner::clauseAfter($source, 2));
    }

    public function testClauseAfterHandlesNesting(): void
    {
        $source = 'Box::<List<int>>';
        $range = TurbofishScanner::clauseAfter($source, 2);
        self::assertSame(strpos($source, '<'), $range['openPos']);
        self::assertSame(strlen($source) - 1, $range['closePos']);
    }

    public function testClauseAfterRejectsSingleColon(): void
    {
        // A single `:` followed by `<` is not a turbofish opener.
        $source = 'Box:<int>';
        self::assertNull(TurbofishScanner::clauseAfter($source, 2));
    }

    public function testClauseAfterRejectsDoubleColonWithoutAngle(): void
    {
        // `Box::BAR` -- `::` present but no `<` follows.
        $source = 'Box::BAR';
        self::assertNull(TurbofishScanner::clauseAfter($source, 2));
    }

    public function testClauseAfterRejectsDoubleColonAtEndOfSource(): void
    {
        // `::` is the last two bytes -- nothing to open.
        $source = 'Box::';
        self::assertNull(TurbofishScanner::clauseAfter($source, 2));
    }

    public function testClauseAfterFindsSingleByteArg(): void
    {
        $source = 'Box::<T>';
        $range = TurbofishScanner::clauseAfter($source, 2);
        self::assertSame(5, $range['openPos']);
        self::assertSame(7, $range['closePos']);
    }

    // --- splitTopLevelArgs ----------------------------------------------

    public function testSplitEmptyInnerYieldsNoArgs(): void
    {
        self::assertSame([], TurbofishScanner::splitTopLevelArgs(''));
        self::assertSame([], TurbofishScanner::splitTopLevelArgs('   '));
    }

    public function testSplitSingleArg(): void
    {
        self::assertSame(['int'], TurbofishScanner::splitTopLevelArgs('int'));
    }

    public function testSplitMultipleArgsTrimmed(): void
    {
        self::assertSame(['int', 'string'], TurbofishScanner::splitTopLevelArgs('int, string'));
    }

    public function testSplitDoesNotBreakOnNestedCommas(): void
    {
        self::assertSame(['Map<K, V>', 'int'], TurbofishScanner::splitTopLevelArgs('Map<K, V>, int'));
    }

    public function testSplitClosesNestingBeforeTrailingComma(): void
    {
        // The `>` must decrement depth so the comma AFTER the nested clause
        // splits at top level.
        self::assertSame(['List<int>', 'string'], TurbofishScanner::splitTopLevelArgs('List<int>, string'));
    }

    public function testSplitDeeplyNested(): void
    {
        self::assertSame(['Map<K, List<V>>', 'int'], TurbofishScanner::splitTopLevelArgs('Map<K, List<V>>, int'));
    }

    public function testSplitTrimsEachArg(): void
    {
        self::assertSame(['A', 'B', 'C'], TurbofishScanner::splitTopLevelArgs('  A ,  B ,C  '));
    }

    // --- topLevelArgIndexAt ---------------------------------------------

    public function testArgIndexAtCountsTopLevelCommas(): void
    {
        $inner = 'Foo, Bar, Baz';
        self::assertSame(0, TurbofishScanner::topLevelArgIndexAt($inner, 1));
        self::assertSame(1, TurbofishScanner::topLevelArgIndexAt($inner, 6));
        self::assertSame(2, TurbofishScanner::topLevelArgIndexAt($inner, 11));
    }

    public function testArgIndexAtIgnoresNestedCommas(): void
    {
        $inner = 'Map<K, V>, int';
        // Offset inside the nested `<K, V>` is still slot 0.
        self::assertSame(0, TurbofishScanner::topLevelArgIndexAt($inner, 6));
        // Offset after the top-level comma is slot 1.
        self::assertSame(1, TurbofishScanner::topLevelArgIndexAt($inner, 11));
    }

    public function testArgIndexAtRejectsOutOfRangeOffset(): void
    {
        self::assertNull(TurbofishScanner::topLevelArgIndexAt('abc', 99));
        self::assertNull(TurbofishScanner::topLevelArgIndexAt('abc', -1));
    }

    public function testArgIndexAtAcceptsOffsetEqualToLength(): void
    {
        // Offset exactly at the end of the inner text is in-range (cursor just
        // past the last byte).
        self::assertSame(1, TurbofishScanner::topLevelArgIndexAt('A,B', 3));
    }

    public function testArgIndexAtClosesNestingThenCounts(): void
    {
        // After the nested `<int>` closes, the top-level comma increments.
        $inner = 'List<int>,X';
        self::assertSame(0, TurbofishScanner::topLevelArgIndexAt($inner, 9));
        self::assertSame(1, TurbofishScanner::topLevelArgIndexAt($inner, 10));
    }
}
