<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Resolver\ClassLikeContext;
use XPHP\Lsp\Resolver\ClassLikeLookup;
use XPHP\Lsp\Resolver\CompositeClassLikeLookup;

final class CompositeClassLikeLookupTest extends TestCase
{
    public function testReturnsFirstHitInChainOrder(): void
    {
        $first = new Class_(new Identifier('First'));
        $second = new Class_(new Identifier('Second'));

        $lookup = new CompositeClassLikeLookup(
            new FixedLookup(['Foo' => $first]),
            new FixedLookup(['Foo' => $second, 'Bar' => $second]),
        );

        self::assertSame($first, $lookup->find('Foo'), 'first lookup wins on collision');
        self::assertSame($second, $lookup->find('Bar'), 'second lookup serves uncovered FQNs');
    }

    public function testReturnsNullWhenNoLookupKnowsFqn(): void
    {
        $lookup = new CompositeClassLikeLookup(
            new FixedLookup([]),
            new FixedLookup([]),
        );

        self::assertNull($lookup->find('Nothing'));
    }

    public function testEmptyChainReturnsNull(): void
    {
        $lookup = new CompositeClassLikeLookup();
        self::assertNull($lookup->find('Anything'));
    }

    public function testFindWithContextDelegatesInChainOrder(): void
    {
        $first = new Class_(new Identifier('First'));
        $second = new Class_(new Identifier('Second'));

        $lookup = new CompositeClassLikeLookup(
            new FixedLookup(['Foo' => $first]),
            new FixedLookup(['Foo' => $second, 'Bar' => $second]),
        );

        self::assertSame($first, $lookup->findWithContext('Foo')?->classLike, 'first lookup wins');
        self::assertSame($second, $lookup->findWithContext('Bar')?->classLike, 'second serves uncovered');
        self::assertNull($lookup->findWithContext('Nothing'));
    }
}

final class FixedLookup implements ClassLikeLookup
{
    /** @param array<string, ClassLike> $entries */
    public function __construct(private readonly array $entries)
    {
    }

    public function find(string $fqn): ?ClassLike
    {
        return $this->entries[$fqn] ?? null;
    }

    public function findWithContext(string $fqn): ?ClassLikeContext
    {
        $hit = $this->entries[$fqn] ?? null;
        return $hit === null ? null : new ClassLikeContext($hit, [], '');
    }
}
