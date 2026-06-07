<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Handler\TypeArgPositionDetector;

final class TypeArgPositionDetectorTest extends TestCase
{
    public function testDetectsImmediatelyAfterOpenBracket(): void
    {
        $source = 'new Box::<';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 0], $hit);
    }

    public function testDetectsWithPartialIdentifierPrefix(): void
    {
        $source = 'new Box::<Pla';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => 'Pla', 'containerName' => 'Box', 'slot' => 0], $hit);
    }

    public function testDetectsAfterCommaInMultiArgList(): void
    {
        $source = 'new Pair::<Foo, ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        // Slot 1 -- cursor sits after the first comma at depth 0.
        self::assertSame(['prefix' => '', 'containerName' => 'Pair', 'slot' => 1], $hit);
    }

    public function testDetectsInsideNestedGenericsAtSameDepth(): void
    {
        $source = 'new Box::<List<int>, ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        // Outermost turbofish is `Box`; the comma inside `List<...>` doesn't
        // count toward Box's slot because it sits at depth 1.
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testRejectsLessThanOperator(): void
    {
        $source = 'if ($a < ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit);
    }

    public function testRejectsBareAngleWithoutTurbofish(): void
    {
        // A bare `Box<` (no `::`) is NOT a call-site clause in 0.2.x.
        $source = 'new Box<';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit);
    }

    public function testRejectsAfterBareDoubleColon(): void
    {
        // `Foo::` without the `<` is a static-member access, not a turbofish.
        $source = 'Foo::';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit);
    }

    public function testRejectsOutsideAnyTypeArgClause(): void
    {
        $source = '$x = new Box(';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit);
    }

    public function testRejectsAfterClosingBracket(): void
    {
        $source = 'new Box::<Plastic> ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit, 'cursor past the closing `>` is no longer in type-arg context');
    }

    public function testAcceptsFqnStylePrefix(): void
    {
        // Backslashes are part of the identifier so an FQN prefix matches as one token.
        $source = 'new Box::<App\\Mo';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNotNull($hit);
        self::assertSame('App\\Mo', $hit['prefix']);
    }

    public function testAcceptsFqnStyleContainerName(): void
    {
        $source = 'new \\App\\Box::<Pla';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNotNull($hit);
        self::assertSame('\\App\\Box', $hit['containerName']);
    }

    public function testHandlesNestedDepthCorrectly(): void
    {
        // Inside the INNER `<…>`, prefix is the partial identifier just typed.
        $source = 'new Box::<List<Pla';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        // Container is the inner `List`; slot 0 inside it. The inner clause is
        // a bare `<` (nested type-arg), so the opener guard there is the depth
        // walk, not the `::` rule.
        self::assertSame(['prefix' => 'Pla', 'containerName' => 'List', 'slot' => 0], $hit);
    }

    public function testOffsetPastSourceLengthReturnsNull(): void
    {
        $hit = TypeArgPositionDetector::detect('abc', 99);
        self::assertNull($hit);
    }

    public function testCursorAtOffsetZeroReturnsNull(): void
    {
        $hit = TypeArgPositionDetector::detect('Box::<int>', 0);
        self::assertNull($hit);
    }

    public function testCursorAfterTabSeparatorAcceptsTypeArgContext(): void
    {
        $source = "new Box::<Foo,\t";
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testCursorAfterNewlineSeparatorAcceptsTypeArgContext(): void
    {
        $source = "new Box::<Foo,\n";
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testCursorAfterCarriageReturnSeparatorAcceptsTypeArgContext(): void
    {
        $source = "new Box::<Foo,\r";
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testCursorAfterCommaWithoutSpaceAcceptsTypeArgContext(): void
    {
        $source = "new Box::<Foo,";
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testCursorAfterSpaceSeparatorAcceptsTypeArgContext(): void
    {
        $source = "new Box::<Foo, ";
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testNonSeparatorAndNonIdentifierByteBreaksContext(): void
    {
        $source = "new Box::<Foo(";
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit);
    }

    public function testDetectsAfterDepthBalancedNestedGenerics(): void
    {
        $source = 'new Box::<Foo<Bar>, ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testIdentifierAtReturnsFullNameAtCursorInsideGenericClause(): void
    {
        // Cursor sits in the middle of `User` -- prefix `Us`, suffix `er`.
        $source = 'identity::<User>(new User())';
        $offset = strpos($source, 'User') + 2; // mid-identifier
        self::assertSame('User', TypeArgPositionDetector::identifierAt($source, $offset));
    }

    public function testIdentifierAtReturnsNullOutsideGenericClause(): void
    {
        $source = '$x = new User();';
        $offset = strpos($source, 'User') + 1;
        self::assertNull(TypeArgPositionDetector::identifierAt($source, $offset));
    }

    public function testIdentifierAtReturnsNullOnWhitespaceInsideGenericClause(): void
    {
        // Cursor on the space between `<` and `User`.  No prefix to the
        // left, no identifier byte at the cursor -> null.
        $source = 'identity::< User>(...)';
        $offset = strpos($source, '< ') + 1; // on the space
        self::assertNull(TypeArgPositionDetector::identifierAt($source, $offset));
    }

    public function testIdentifierAtReturnsFqnStyleNameWithBackslashes(): void
    {
        $source = 'identity::<App\\Models\\User>(...)';
        $offset = strpos($source, 'User') + 1;
        self::assertSame('App\\Models\\User', TypeArgPositionDetector::identifierAt($source, $offset));
    }
}
