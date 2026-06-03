<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\ParserFactory;
use Phpactor\LanguageServerProtocol\DocumentHighlightKind;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Resolver\DocumentHighlightKindResolver;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Read/write classification of document-highlight occurrences.
 */
final class DocumentHighlightKindResolverTest extends TestCase
{
    public function testClassifiesVariablesByContext(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App;
        function demo(): void {
            $w = 1;
            echo $w;
            $w++;
            foreach ([1, 2] as $v) {}
        }
        PHP;

        self::assertSame(DocumentHighlightKind::WRITE, $this->kindAt($source, $this->offsetOf($source, '$w = 1', '$w')));
        self::assertSame(DocumentHighlightKind::READ, $this->kindAt($source, $this->offsetOf($source, 'echo $w', '$w')));
        self::assertSame(DocumentHighlightKind::WRITE, $this->kindAt($source, $this->offsetOf($source, '$w++', '$w')));
        self::assertSame(DocumentHighlightKind::WRITE, $this->kindAt($source, $this->offsetOf($source, 'as $v', '$v')));
    }

    public function testClassifiesDeclarationsAsWriteAndUsesAsRead(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App;
        class Foo {}
        function make(): Foo {
            return new Foo();
        }
        PHP;

        self::assertSame(DocumentHighlightKind::WRITE, $this->kindAt($source, $this->offsetOf($source, 'class Foo', 'Foo')));
        self::assertSame(DocumentHighlightKind::READ, $this->kindAt($source, $this->offsetOf($source, 'new Foo', 'Foo')));
    }

    public function testClassifiesPropertyWriteVersusRead(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App;
        class Counter {
            public int $n = 0;
            public function bump(): void {
                $this->n = 1;
            }
            public function value(): int {
                return $this->n;
            }
        }
        PHP;

        self::assertSame(DocumentHighlightKind::WRITE, $this->kindAt($source, $this->offsetOf($source, '$this->n = 1', 'n')));
        self::assertSame(DocumentHighlightKind::READ, $this->kindAt($source, $this->offsetOf($source, '$this->n;', 'n')));
    }

    /**
     * Resolve the highlight kind at a single original-source byte offset.
     */
    private function kindAt(string $source, int $offset): int
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        [$ast, $map] = $parser->parseWithMap($source);
        $kinds = (new DocumentHighlightKindResolver())->resolve($ast ?? [], $map, [$offset]);
        self::assertArrayHasKey($offset, $kinds, "no kind resolved at offset {$offset}");
        return $kinds[$offset];
    }

    /**
     * Byte offset of `$needle` within the first occurrence of `$context`.
     */
    private function offsetOf(string $source, string $context, string $needle): int
    {
        $ctxAt = strpos($source, $context);
        self::assertNotFalse($ctxAt, "context not found: {$context}");
        $needleAt = strpos($source, $needle, $ctxAt);
        self::assertNotFalse($needleAt, "needle not found: {$needle}");
        return $needleAt;
    }
}
