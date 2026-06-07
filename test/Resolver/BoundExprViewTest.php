<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Resolver\BoundExprView;
use XPHP\Transpiler\Monomorphize\BoundIntersection;
use XPHP\Transpiler\Monomorphize\BoundLeaf;
use XPHP\Transpiler\Monomorphize\BoundUnion;
use XPHP\Transpiler\Monomorphize\TypeRef;

final class BoundExprViewTest extends TestCase
{
    private static function leaf(string $fqn, TypeRef ...$args): BoundLeaf
    {
        return new BoundLeaf(new TypeRef($fqn, $args));
    }

    public function testDisplayStringNullForAbsentBound(): void
    {
        self::assertNull(BoundExprView::displayString(null));
    }

    public function testDisplayStringRendersLeafWithLeadingBackslash(): void
    {
        self::assertSame('\\App\\Stringy', BoundExprView::displayString(self::leaf('App\\Stringy')));
    }

    public function testDisplayStringNormalisesExistingLeadingBackslash(): void
    {
        self::assertSame('\\Stringable', BoundExprView::displayString(self::leaf('\\Stringable')));
    }

    public function testDisplayStringRendersFBoundedTypeParamWithoutBackslash(): void
    {
        // `T : Comparable<T>` -- the inner `T` is a type-param reference, not a
        // class, so it must NOT pick up the leading FQN backslash.
        $bound = self::leaf('Comparable', new TypeRef('T', [], false, true));
        self::assertSame('\\Comparable<T>', BoundExprView::displayString($bound));
    }

    public function testDisplayStringRendersIntersection(): void
    {
        $bound = new BoundIntersection(self::leaf('A'), self::leaf('B'));
        self::assertSame('\\A & \\B', BoundExprView::displayString($bound));
    }

    public function testDisplayStringRendersUnion(): void
    {
        $bound = new BoundUnion(self::leaf('A'), self::leaf('B'));
        self::assertSame('\\A | \\B', BoundExprView::displayString($bound));
    }

    public function testDisplayStringParenthesisesDnf(): void
    {
        // (A & B) | C
        $bound = new BoundUnion(
            new BoundIntersection(self::leaf('A'), self::leaf('B')),
            self::leaf('C'),
        );
        self::assertSame('(\\A & \\B) | \\C', BoundExprView::displayString($bound));
    }

    public function testDisplayStringParenthesisesUnionInsideIntersection(): void
    {
        // (A | B) & C
        $bound = new BoundIntersection(
            new BoundUnion(self::leaf('A'), self::leaf('B')),
            self::leaf('C'),
        );
        self::assertSame('(\\A | \\B) & \\C', BoundExprView::displayString($bound));
    }

    public function testLeafFqnsEmptyForNull(): void
    {
        self::assertSame([], BoundExprView::leafFqns(null));
    }

    public function testLeafFqnsStripsLeadingBackslash(): void
    {
        self::assertSame(['Stringable'], BoundExprView::leafFqns(self::leaf('\\Stringable')));
    }

    public function testLeafFqnsFlattensComposite(): void
    {
        $bound = new BoundUnion(
            new BoundIntersection(self::leaf('A'), self::leaf('B')),
            self::leaf('C'),
        );
        self::assertSame(['A', 'B', 'C'], BoundExprView::leafFqns($bound));
    }

    public function testIsSatisfiedByNullBoundIsAlwaysTrue(): void
    {
        $never = static fn (string $c, string $b): bool => false;
        self::assertTrue(BoundExprView::isSatisfiedBy('X', null, $never));
    }

    public function testIsSatisfiedByLeafDelegatesToOracle(): void
    {
        $isSubtype = static fn (string $c, string $b): bool => $c === 'Sub' && $b === 'Stringable';
        self::assertTrue(BoundExprView::isSatisfiedBy('Sub', self::leaf('\\Stringable'), $isSubtype));
        self::assertFalse(BoundExprView::isSatisfiedBy('Other', self::leaf('\\Stringable'), $isSubtype));
    }

    public function testIsSatisfiedByIntersectionRequiresAllLeaves(): void
    {
        $bound = new BoundIntersection(self::leaf('A'), self::leaf('B'));
        // Implements A and B.
        $both = static fn (string $c, string $b): bool => in_array($b, ['A', 'B'], true);
        self::assertTrue(BoundExprView::isSatisfiedBy('X', $bound, $both));
        // Implements only A.
        $onlyA = static fn (string $c, string $b): bool => $b === 'A';
        self::assertFalse(BoundExprView::isSatisfiedBy('X', $bound, $onlyA));
    }

    public function testIsSatisfiedByUnionAcceptsAnyLeaf(): void
    {
        $bound = new BoundUnion(self::leaf('A'), self::leaf('B'));
        $onlyB = static fn (string $c, string $b): bool => $b === 'B';
        self::assertTrue(BoundExprView::isSatisfiedBy('X', $bound, $onlyB));
        $neither = static fn (string $c, string $b): bool => false;
        self::assertFalse(BoundExprView::isSatisfiedBy('X', $bound, $neither));
    }
}
