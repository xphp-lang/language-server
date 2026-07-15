<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\Node\Name;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Resolver\ClosureSignatureView;
use XPHP\Transpiler\Monomorphize\ClosureSignature;
use XPHP\Transpiler\Monomorphize\ClosureSignatureParam;
use XPHP\Transpiler\Monomorphize\SigClosure;
use XPHP\Transpiler\Monomorphize\SigIntersection;
use XPHP\Transpiler\Monomorphize\SigRaw;
use XPHP\Transpiler\Monomorphize\SigType;
use XPHP\Transpiler\Monomorphize\SigTypeRef;
use XPHP\Transpiler\Monomorphize\SigUnion;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Pins {@see ClosureSignatureView::render} -- the source-shaped rendering of an
 * xphp 0.3.0 `Closure(...)` signature type (params + return, DNF, generic args,
 * by-ref / variadic modifiers). A closure type has no parameter names, so params
 * render as bare types.
 */
final class ClosureSignatureViewTest extends TestCase
{
    private static function leaf(string $name, bool $isScalar = true): SigTypeRef
    {
        return new SigTypeRef(new TypeRef($name, [], $isScalar, false));
    }

    private static function param(SigType $type, bool $byRef = false, bool $variadic = false): ClosureSignatureParam
    {
        return new ClosureSignatureParam($type, $byRef, $variadic, false);
    }

    public function testRendersScalarParamsAndReturn(): void
    {
        $sig = new ClosureSignature(
            [self::param(self::leaf('int')), self::param(self::leaf('string'))],
            self::leaf('bool'),
        );
        self::assertSame('Closure(int, string): bool', ClosureSignatureView::render($sig));
    }

    public function testRendersNoParamsVoidReturn(): void
    {
        $sig = new ClosureSignature([], self::leaf('void'));
        self::assertSame('Closure(): void', ClosureSignatureView::render($sig));
    }

    public function testNullableWholeTypeGetsLeadingQuestionMark(): void
    {
        $sig = new ClosureSignature([self::param(self::leaf('int'))], self::leaf('bool'), true);
        self::assertSame('?Closure(int): bool', ClosureSignatureView::render($sig));
    }

    public function testNullReturnOmitsReturnSuffix(): void
    {
        $sig = new ClosureSignature([self::param(self::leaf('int'))], null);
        self::assertSame('Closure(int)', ClosureSignatureView::render($sig));
    }

    public function testByRefAndVariadicModifiers(): void
    {
        $sig = new ClosureSignature(
            [self::param(self::leaf('int'), byRef: true), self::param(self::leaf('string'), variadic: true)],
            self::leaf('void'),
        );
        self::assertSame('Closure(int&, string...): void', ClosureSignatureView::render($sig));
    }

    public function testGenericArgLeafUsesTypeRefDisplay(): void
    {
        $box = new SigTypeRef(new TypeRef('App\\Box', [new TypeRef('int', [], true, false)], false, false));
        $sig = new ClosureSignature([self::param($box)], self::leaf('void'));
        // Class leaves get the leading `\` of a fully-qualified name (matching
        // the rest of the hover card); the scalar arg stays bare.
        self::assertSame('Closure(\\App\\Box<int>): void', ClosureSignatureView::render($sig));
    }

    public function testUnionParam(): void
    {
        $union = new SigUnion([self::leaf('int'), self::leaf('string')]);
        $sig = new ClosureSignature([self::param($union)], self::leaf('void'));
        self::assertSame('Closure(int|string): void', ClosureSignatureView::render($sig));
    }

    public function testIntersectionReturn(): void
    {
        $inter = new SigIntersection([self::leaf('A', false), self::leaf('B', false)]);
        $sig = new ClosureSignature([], $inter);
        self::assertSame('Closure(): \\A&\\B', ClosureSignatureView::render($sig));
    }

    public function testDnfParenthesisesIntersectionInsideUnion(): void
    {
        // (A&B)|C -- exercises the defensive wrapForUnion path. The real parser
        // keeps a DNF member as a single SigRaw slice (see testRawMemberIsPassedThrough),
        // but the view must still parenthesise a structured intersection-in-union.
        $inter = new SigIntersection([self::leaf('A', false), self::leaf('B', false)]);
        $dnf = new SigUnion([$inter, self::leaf('C', false)]);
        $sig = new ClosureSignature([self::param($dnf)], self::leaf('void'));
        self::assertSame('Closure((\\A&\\B)|\\C): void', ClosureSignatureView::render($sig));
    }

    public function testNestedClosureRecurses(): void
    {
        // Closure(Closure(int): bool): void -- a SigClosure member must recurse
        // through render(), not degrade to an empty string.
        $inner = new SigClosure(new ClosureSignature([self::param(self::leaf('int'))], self::leaf('bool')));
        $sig = new ClosureSignature([self::param($inner)], self::leaf('void'));
        self::assertSame('Closure(Closure(int): bool): void', ClosureSignatureView::render($sig));
    }

    public function testRawMemberIsPassedThrough(): void
    {
        // The token scanner keeps composite/DNF forms as an already-display-ready
        // SigRaw slice; the view emits it verbatim rather than dropping it.
        $sig = new ClosureSignature([self::param(new SigRaw('(A&B)|C'))], self::leaf('void'));
        self::assertSame('Closure((A&B)|C): void', ClosureSignatureView::render($sig));
    }

    public function testRendersRealParserPayload(): void
    {
        // Exercise the actual ClosureSignature the parser stamps, not a hand-built one.
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        [$ast] = $parser->parseWithMap(
            "<?php\nnamespace App;\nfunction h(Closure(int \$x, string \$y): bool \$cb): void {}\n"
        );
        $sig = null;
        foreach ((new NodeFinder())->findInstanceOf($ast, Name::class) as $name) {
            $candidate = $name->getAttribute(XphpSourceParser::ATTR_CLOSURE_SIG);
            if ($candidate instanceof ClosureSignature) {
                $sig = $candidate;
                break;
            }
        }
        self::assertInstanceOf(ClosureSignature::class, $sig);
        self::assertSame('Closure(int, string): bool', ClosureSignatureView::render($sig));
    }
}
