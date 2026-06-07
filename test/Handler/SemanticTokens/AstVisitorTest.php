<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler\SemanticTokens;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Handler\SemanticTokens\AstVisitor;
use XPHP\Lsp\Handler\SemanticTokens\TokenSpec;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Tests the token classification table for {@see AstVisitor}.
 *
 * Each test feeds a snippet and asserts that the visitor emits a
 * TokenSpec covering the expected substring at the expected
 * classification.  Position assertions use byte offsets via
 * {@see findByOffset}; classification assertions use
 * {@see assertTokenAt}.
 */
final class AstVisitorTest extends TestCase
{
    // --- Pass 1: tokens ---------------------------------------------------

    public function testReservedWordIdentifiersAreClassifiedAsKeywords(): void
    {
        // PHP tokenizes null / true / false / void / int / etc as
        // T_STRING (bareword identifiers), not as T_* keyword constants.
        // The visitor's identifier-recognition path emits these as
        // `keyword` regardless.
        $source = "<?php\n\$a = null;\n\$b = true;\n\$c = false;\n\$d = NULL;";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'null', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'true', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'false', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'NULL', 'keyword');
    }

    public function testKeywordsAreClassified(): void
    {
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Foo {
            public function bar(): void { return; }
        }
        XPHP;
        $specs = $this->collect($source);

        $this->assertTokenSubstring($specs, $source, 'namespace', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'class', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'public', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'function', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'return', 'keyword');
    }

    public function testVariablesAreClassified(): void
    {
        $source = "<?php\n\$name = 1;";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, '$name', 'variable');
    }

    public function testNumbersAreClassified(): void
    {
        $source = "<?php\n\$x = 42;\n\$y = 3.14;";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, '42', 'number');
        $this->assertTokenSubstring($specs, $source, '3.14', 'number');
    }

    public function testSingleQuotedStringIsClassifiedAsString(): void
    {
        $source = "<?php\n\$x = 'hello';";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, "'hello'", 'string');
    }

    public function testLineCommentIsClassifiedAsComment(): void
    {
        $source = "<?php\n// a comment\n\$x = 1;";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, '// a comment', 'comment');
    }

    public function testBlockCommentIsClassifiedAsComment(): void
    {
        $source = "<?php\n/* block */\n\$x = 1;";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, '/* block */', 'comment');
    }

    public function testDocCommentIsClassifiedAsComment(): void
    {
        $source = "<?php\n/** doc */\nclass X {}";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, '/** doc */', 'comment');
    }

    // --- Pass 2: AST -------------------------------------------------------

    public function testClassNameIsClassified(): void
    {
        $source = "<?php\nnamespace App;\nclass User {}";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'User', 'class');
    }

    public function testInterfaceNameIsClassifiedAsInterface(): void
    {
        $source = "<?php\nnamespace App;\ninterface Greeter {}";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Greeter', 'interface');
    }

    public function testEnumNameIsClassifiedAsEnum(): void
    {
        $source = "<?php\nnamespace App;\nenum Status { case Active; }";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Status', 'enum');
    }

    public function testMethodNameIsClassifiedAsMethod(): void
    {
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Foo { public function bar(): void {} }
        XPHP;
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'bar', 'method');
    }

    public function testTopLevelFunctionNameIsClassifiedAsFunction(): void
    {
        $source = <<<'XPHP'
        <?php
        namespace App;
        function greet(): void {}
        XPHP;
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'greet', 'function');
    }

    public function testParameterEmitsExactlyOneParameterSpec(): void
    {
        // Single spec at `$name`, type `parameter` -- NOT two specs
        // (variable + parameter) at the same span.  The reclassify-map
        // path tells the token pass to emit `parameter` instead of
        // `variable` at the param's offset.
        $source = <<<'XPHP'
        <?php
        namespace App;
        function greet(string $name): void {}
        XPHP;
        $specs = $this->collect($source);

        $atName = array_values(array_filter(
            $specs,
            fn (TokenSpec $s) => self::substring($source, $s) === '$name',
        ));
        self::assertCount(
            1,
            $atName,
            'expected exactly one spec at `$name`; got ' . count($atName)
                . ' (' . implode(',', array_map(fn (TokenSpec $s) => $s->type, $atName)) . ')',
        );
        self::assertSame('parameter', $atName[0]->type);
    }

    // --- Edge cases --------------------------------------------------------

    public function testEmptyFileEmitsNoSpecs(): void
    {
        $source = '';
        $specs = $this->collect($source);
        self::assertSame([], $specs);
    }

    public function testSourceWithOnlyOpenTagDoesNotCrash(): void
    {
        $source = '<?php';
        $specs = $this->collect($source);
        // Just the open tag keyword.
        self::assertNotEmpty($specs);
    }

    public function testCommentBeforeClassDeclaration(): void
    {
        $source = <<<'XPHP'
        <?php
        // Header.
        class X {}
        XPHP;
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, '// Header.', 'comment');
        $this->assertTokenSubstring($specs, $source, 'class', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'X', 'class');
    }

    public function testXphpAngleBracketStripDoesNotMisalignAstPositions(): void
    {
        // Box<T> -- nikic parses the STRIPPED source ("class Box {").
        // ByteOffsetMap must translate AST positions back to the
        // original buffer so `Box`'s emitted span lines up.
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Box<T> {}
        XPHP;
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Box', 'class');
    }

    // --- Slice 3: xphp generic-syntax classifications --------------------

    public function testClassDeclarationTypeParamPaintsAsTypeParameter(): void
    {
        // Form 1: class Box<T> -- T inside <...> is typeParameter.
        $source = "<?php\nclass Box<T> {}";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'T', 'typeParameter');
    }

    public function testBoundTypeParamPaintsAsTypeParameter(): void
    {
        // Form 2: class StringableBox<T: \Stringable> -- T is typeParameter;
        // Stringable is a class reference inside the clause (also painted
        // as typeParameter under our broad inside-clause rule for now).
        // The FQN `\Stringable` comes back as a single T_NAME_FULLY_QUALIFIED
        // token from PHP 8.0+'s tokenizer, so the emitted span includes
        // the leading backslash.
        $source = "<?php\nclass StringableBox<T: \\Stringable> {}";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'T', 'typeParameter');
        $this->assertTokenSubstring($specs, $source, '\Stringable', 'typeParameter');
    }

    public function testTypeArgClausePaintsInsideBoxOfPlastic(): void
    {
        // Form 6: new Box::<Plastic>() -- `Plastic` inside the turbofish is
        // typeParameter. The clause opens on `<` preceded by `::`.
        $source = "<?php\n\$b = new Box::<Plastic>();";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Plastic', 'typeParameter');
    }

    public function testNestedTypeArgClause(): void
    {
        // Nested: Box::<Lst<T>> -- the outer clause is a turbofish; the inner
        // `Lst<T>` is a bare nested type-arg. Both `Lst` and `T` are
        // typeParameter.
        $source = "<?php\n\$b = new Box::<Lst<T>>();";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Lst', 'typeParameter');
        $this->assertTokenSubstring($specs, $source, 'T', 'typeParameter');
    }

    public function testStaticCallTurbofishPaintsTypeArg(): void
    {
        // Util::identity::<int>(42) -- the call-site turbofish opens on the
        // `<` after `::`; the lowercase scalar `int` inside is typeParameter
        // (the `::` makes the clause unambiguous against `<` comparison).
        $source = "<?php\nUtil::identity::<int>(\$x);";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'int', 'typeParameter');
    }

    public function testStaticReceiverTurbofishPaintsTypeArg(): void
    {
        // static::<T>() -- the receiver before `::` is the T_STATIC keyword;
        // the clause still opens on the `<` after `::`.
        $source = "<?php\nstatic::<Plastic>();";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Plastic', 'typeParameter');
    }

    public function testSelfTurbofishPaintsTypeArg(): void
    {
        // `self::<Plastic>()` -- the pseudo-type turbofish opens a clause after
        // the `::`; `Plastic` inside is a typeParameter.
        $source = "<?php\nnew self::<Plastic>();";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Plastic', 'typeParameter');
    }

    public function testParentTurbofishPaintsTypeArg(): void
    {
        $source = "<?php\nparent::method::<Plastic>();";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Plastic', 'typeParameter');
    }

    public function testStaticReceiverIsClassifiedAsKeyword(): void
    {
        // `static` before `::<` stays a keyword.
        $source = "<?php\nstatic::<Plastic>();";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'static', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'Plastic', 'typeParameter');
    }

    public function testArrowClosureDeclarationClausePaintsTypeParam(): void
    {
        // `fn<T>(…)` -- the declaration clause `<T>` opens after the `fn`
        // keyword; `T` is a typeParameter.
        $source = "<?php\n\$g = fn<T>(\$x) => \$x;";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'T', 'typeParameter');
    }

    public function testClosureDeclarationClausePaintsTypeParam(): void
    {
        // `function<U>(…)` -- the declaration clause opens after `function`.
        $source = "<?php\n\$h = function<U>(\$y) { return \$y; };";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'U', 'typeParameter');
    }

    public function testGenericClosureBodyReifiedTPaintsAsTypeParameter(): void
    {
        // Body-level `T` inside a generic closure re-classifies via the
        // closure's ATTR_METHOD_GENERIC_PARAMS frame -- assert the `new T()`
        // body reference SPECIFICALLY (not just the decl-clause `T`, which the
        // token pass classifies independently).
        $source = "<?php\n\$make = function<T>() { return new T(); };";
        $specs = $this->collect($source);
        $bodyTOffset = strpos($source, 'new T()') + strlen('new ');
        $bodyTSpecs = array_filter(
            $specs,
            fn (TokenSpec $s) => self::substring($source, $s) === 'T'
                && $s->type === 'typeParameter'
                && self::specByteOffset($source, $s) === $bodyTOffset,
        );
        self::assertCount(1, $bodyTSpecs, 'the body-level new T() must classify as typeParameter');
    }

    public function testTReferenceOutsideGenericClosureNotMisclassified(): void
    {
        // After the generic closure's frame is popped, a bare `new T()` in a
        // non-generic context must NOT classify as a type parameter.
        $source = "<?php\n\$g = function<T>() { return new T(); };\nnew T();";
        $specs = $this->collect($source);
        // The trailing `new T()` (line 2, after the closure) sits at a byte
        // offset past the closure; assert no typeParameter spec starts there.
        $closureEnd = strpos($source, '};');
        $strayT = array_filter(
            $specs,
            fn (TokenSpec $s) => $s->type === 'typeParameter'
                && self::substring($source, $s) === 'T'
                && self::specByteOffset($source, $s) > $closureEnd,
        );
        self::assertEmpty($strayT, 'T outside the closure frame must not be a type parameter');
    }

    public function testBareDoubleColonWithoutAngleOpensNothing(): void
    {
        // `Foo::BAR` is a constant access -- no `<` follows the `::`, so no
        // type-arg clause opens.
        $source = "<?php\n\$x = Foo::BAR;";
        $specs = $this->collect($source);
        $typeParamSpecs = array_filter($specs, fn (TokenSpec $s) => $s->type === 'typeParameter');
        self::assertEmpty($typeParamSpecs);
    }

    public function testTurbofishWithLowercaseScalarFirstArgOpensClause(): void
    {
        // `Box::<int>()` -- the `::` anchor makes the clause unambiguous, so a
        // lowercase scalar first arg MUST open it and be painted. (The
        // uppercase-ident guard belongs to the bare-`<` declaration branch, not
        // the turbofish branch, where it would drop the whole clause.)
        $source = "<?php\nnew Box::<int>();";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'int', 'typeParameter');
    }

    public function testTurbofishMultipleArgsLowercaseFirstHighlightsAll(): void
    {
        // `Map::<int, User>()` -- a lowercase first arg must not suppress the
        // whole clause: both the scalar `int` and the class `User` in the later
        // slot are type arguments and must be painted.
        $source = "<?php\nnew Map::<int, User>();";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'int', 'typeParameter');
        $this->assertTokenSubstring($specs, $source, 'User', 'typeParameter');
    }

    public function testTurbofishClauseClosesSoTrailingNameIsNotTypeParam(): void
    {
        // After `Box::<Plastic>` closes, the trailing `Other` identifier must
        // NOT be classified as a type parameter. Locks the `$genericDepth = 1`
        // open and the `>` decrement that returns depth to 0.
        $source = "<?php\nBox::<Plastic>; class Other {}";
        $specs = $this->collect($source);
        $otherSpecs = array_filter(
            $specs,
            fn (TokenSpec $s) => self::substring($source, $s) === 'Other' && $s->type === 'typeParameter',
        );
        self::assertEmpty($otherSpecs, 'identifier after a closed turbofish must not be a type parameter');
    }

    public function testMultipleTypeArgsSeparatedByComma(): void
    {
        // Form 9: Pair<K, V> -- both K and V are typeParameter.
        $source = "<?php\nclass Pair<K, V> {}";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'K', 'typeParameter');
        $this->assertTokenSubstring($specs, $source, 'V', 'typeParameter');
    }

    public function testLessThanComparisonIsNotMisclassified(): void
    {
        // Counter-example: $a < $b -- the `<` opens nothing because the
        // previous token is T_VARIABLE, not T_STRING.
        $source = "<?php\nif (\$a < \$b) { return 0; }";
        $specs = $this->collect($source);
        // No typeParameter spec anywhere.
        $typeParamSpecs = array_filter($specs, fn (TokenSpec $s) => $s->type === 'typeParameter');
        self::assertEmpty($typeParamSpecs, 'comparison `$a < $b` produced typeParameter spec');
    }

    public function testNumberComparisonIsNotMisclassified(): void
    {
        $source = "<?php\nif (\$x < 5) { return 0; }";
        $specs = $this->collect($source);
        $typeParamSpecs = array_filter($specs, fn (TokenSpec $s) => $s->type === 'typeParameter');
        self::assertEmpty($typeParamSpecs);
    }

    public function testLowercaseFunctionCallComparisonIsNotMisclassified(): void
    {
        // `$size < count(...)` -- the `<` follows a T_VARIABLE (and there is no
        // `::` before it), so none of the clause-opener branches fire. The
        // comparison is never mistaken for a turbofish.
        $source = "<?php\nif (\$size < count(\$items)) { return 0; }";
        $specs = $this->collect($source);
        $typeParamSpecs = array_filter($specs, fn (TokenSpec $s) => $s->type === 'typeParameter');
        self::assertEmpty($typeParamSpecs);
    }

    public function testLessThanBeforeClassNameIsNotMistakenForTurbofish(): void
    {
        // `$x < Foo` -- the `<` follows a T_VARIABLE with no `::` before it, so
        // it is a comparison, not a turbofish. Only a `::`-anchored `<` opens
        // the call-site clause; the bareword `Foo` must NOT be a typeParameter.
        $source = "<?php\n\$ok = \$x < Foo;";
        $specs = $this->collect($source);
        $typeParamSpecs = array_filter($specs, fn (TokenSpec $s) => $s->type === 'typeParameter');
        self::assertEmpty($typeParamSpecs);
    }

    public function testReifiedNewTPaintsAsTypeParameter(): void
    {
        // Form 10: `new T(...)` inside a class body whose template has T.
        // The AST's ATTR_GENERIC_PARAMS on the enclosing ClassLike puts T
        // in scope; the Name node 'T' inside `new T()` re-classifies.
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Reified<T> {
            public function make(): T { return new T(); }
        }
        XPHP;
        $specs = $this->collect($source);

        // Multiple `T` references in source.  Assert at least one
        // typeParameter at `T` (the `new T()` position).
        $tSpecs = array_filter(
            $specs,
            fn (TokenSpec $s) => self::substring($source, $s) === 'T' && $s->type === 'typeParameter',
        );
        self::assertNotEmpty($tSpecs, 'expected at least one typeParameter at `T` in reified body');
    }

    public function testReifiedTClassPaintsAsTypeParameter(): void
    {
        // Form 11: `T::class` inside a generic body.
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Reified<T> {
            public function name(): string { return T::class; }
        }
        XPHP;
        $specs = $this->collect($source);

        // The Name 'T' before ::class must be typeParameter.  There may
        // be multiple T's (the return type, the T::class one); assert at
        // least one.
        $tSpecs = array_filter(
            $specs,
            fn (TokenSpec $s) => self::substring($source, $s) === 'T' && $s->type === 'typeParameter',
        );
        self::assertNotEmpty($tSpecs);
    }

    public function testInstanceofTPaintsAsTypeParameter(): void
    {
        // Form 12: `instanceof T`.
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Reified<T> {
            public function check(mixed $x): bool { return $x instanceof T; }
        }
        XPHP;
        $specs = $this->collect($source);
        $tSpecs = array_filter(
            $specs,
            fn (TokenSpec $s) => self::substring($source, $s) === 'T' && $s->type === 'typeParameter',
        );
        self::assertNotEmpty($tSpecs);
    }

    public function testReifiedTOutsideGenericBodyIsNotMisclassified(): void
    {
        // Counter-example: `new Foo()` in a non-generic class -- the
        // single-letter heuristic by itself would match `Foo`, but the
        // scope-stack-based detection requires Foo to be in
        // ATTR_GENERIC_PARAMS of an enclosing class.  Plain ClassLike
        // without ATTR_GENERIC_PARAMS doesn't push T into scope, so
        // `new Foo()` stays unclassified by the reified-T path.
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Plain {
            public function go(): void { $x = new Foo(); }
        }
        XPHP;
        $specs = $this->collect($source);
        $fooSpecs = array_filter(
            $specs,
            fn (TokenSpec $s) => self::substring($source, $s) === 'Foo' && $s->type === 'typeParameter',
        );
        self::assertEmpty($fooSpecs, '`Foo` outside a generic body must not be classified as typeParameter');
    }

    public function testInterpolatedStringDecomposesIntoLiteralSlabsAndVariable(): void
    {
        $source = <<<'XPHP'
        <?php
        $name = 'x';
        $g = "Hello $name world";
        XPHP;
        $specs = $this->collect($source);

        // The interpolated variable highlights as a variable INSIDE the string.
        $this->assertTokenSubstring($specs, $source, '$name', 'variable');
        // The literal slabs on either side stay `string`.
        $this->assertTokenSubstring($specs, $source, 'Hello ', 'string');
        $this->assertTokenSubstring($specs, $source, ' world', 'string');
    }

    public function testNonAsciiStringLengthIsUtf16NotBytes(): void
    {
        // `"café"` is 6 UTF-16 code units (quotes + c, a, f, é) but 7 bytes
        // (é is 2 bytes in UTF-8). The emitted length must be the UTF-16 count.
        $source = "<?php\n\$g = \"caf\u{00e9}\";\n";
        $token = $this->firstSpecOfType($this->collect($source), 'string');

        self::assertNotNull($token, 'expected a string token');
        self::assertSame(6, $token->length, 'token length must be UTF-16 code units, not bytes');
    }

    public function testFourByteCharacterCountsAsTwoUtf16Units(): void
    {
        // A supplementary-plane char (😀, 4 UTF-8 bytes) is a UTF-16 surrogate
        // pair (2 units). `"😀"` => quote + 2 + quote = 4 UTF-16 units.
        $source = "<?php\n\$g = \"\u{1F600}\";\n";
        $token = $this->firstSpecOfType($this->collect($source), 'string');

        self::assertNotNull($token, 'expected a string token');
        self::assertSame(4, $token->length);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param list<TokenSpec> $specs
     */
    private function firstSpecOfType(array $specs, string $type): ?TokenSpec
    {
        foreach ($specs as $spec) {
            if ($spec->type === $type) {
                return $spec;
            }
        }
        return null;
    }

    /**
     * @return list<TokenSpec>
     */
    private function collect(string $source): array
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        try {
            [$ast, $byteOffsetMap] = $parser->parseWithMap($source);
        } catch (\Throwable $e) {
            $ast = [];
            $byteOffsetMap = \XPHP\Transpiler\Monomorphize\ByteOffsetMap::identity();
        }
        $visitor = new AstVisitor(
            new PositionMap($source),
            $byteOffsetMap,
            $source,
        );
        return $visitor->visit($ast ?? []);
    }

    /**
     * Find a TokenSpec whose source span exactly equals `$needle` and assert
     * its type matches `$expectedType`.
     *
     * @param list<TokenSpec> $specs
     */
    private function assertTokenSubstring(array $specs, string $source, string $needle, string $expectedType): void
    {
        foreach ($specs as $spec) {
            if (self::substring($source, $spec) === $needle && $spec->type === $expectedType) {
                self::assertTrue(true);
                return;
            }
        }
        $diag = array_map(
            static fn (TokenSpec $s): string => sprintf(
                "L%d C%d len=%d %s = %s",
                $s->line,
                $s->startChar,
                $s->length,
                $s->type,
                json_encode(self::substring($source, $s)),
            ),
            $specs,
        );
        self::fail("no `$expectedType` spec found at `$needle`; saw:\n  " . implode("\n  ", $diag));
    }

    private static function substring(string $source, TokenSpec $spec): string
    {
        return substr($source, self::specByteOffset($source, $spec), $spec->length);
    }

    private static function specByteOffset(string $source, TokenSpec $spec): int
    {
        // Convert (line, char) back to byte offset.
        $lines = explode("\n", $source);
        $byteOffset = 0;
        for ($i = 0; $i < $spec->line && $i < count($lines); $i++) {
            $byteOffset += strlen($lines[$i]) + 1; // +1 for the \n
        }
        return $byteOffset + $spec->startChar;
    }
}
