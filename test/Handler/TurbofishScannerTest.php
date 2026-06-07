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
}
