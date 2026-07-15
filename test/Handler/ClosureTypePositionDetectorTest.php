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
}
