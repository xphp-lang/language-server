<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\SemanticTokens;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\SemanticTokens\TokenLegend;
use XPHP\Lsp\Handler\XphpSemanticTokensHandler;
use XPHP\Lsp\Test\Support\AssertsRangeWithinDocument;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpSemanticTokensHandlerTest extends TestCase
{
    use AssertsRangeWithinDocument;

    /**
     * W7 invariant: every semantic token the handler emits must sit inside the
     * document. A strict client annotator (PhpStorm) throws "Range must be
     * inside element being annotated" otherwise. Tokens are delta-encoded and
     * single-line (the builder splits multi-line tokens one-per-line), so each
     * decodes to `(line, char)..(line, char + length)`.
     */
    public function testEmittedTokensAreWithinDocumentBounds(): void
    {
        $source = <<<'XPHP'
        <?php
        namespace App;

        use App\Models\User;

        class Collection<T>
        {
            public T $first;

            public function map(callable $fn): Collection
            {
                // a comment spanning the method body
                return $this;
            }
        }

        $users = new Collection::<User>();
        $mapped = $users->map(fn ($u) => $u);
        XPHP;
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/bounds.xphp', 'xphp', 1, $source));

        $result = wait($this->newHandler($workspace)->semanticTokensFull(['uri' => '/bounds.xphp']));

        self::assertInstanceOf(SemanticTokens::class, $result);
        self::assertNotEmpty($result->data, 'fixture should produce tokens to check');
        foreach (self::decodeTokens($result->data) as $i => $token) {
            self::assertRangeWithinDocument(
                $source,
                $token['line'],
                $token['char'],
                $token['line'],
                $token['char'] + $token['length'],
                "semantic token #{$i}",
            );
        }
    }

    /**
     * Decode the delta-encoded `data` stream (5 ints per token: deltaLine,
     * deltaChar, length, typeIndex, modifierMask) into absolute positions.
     *
     * @param  list<int> $data
     * @return list<array{line: int, char: int, length: int}>
     */
    private static function decodeTokens(array $data): array
    {
        $out = [];
        $line = 0;
        $char = 0;
        for ($i = 0; $i + 5 <= count($data); $i += 5) {
            [$deltaLine, $deltaChar, $length] = array_slice($data, $i, 3);
            if ($deltaLine > 0) {
                $line += $deltaLine;
                $char = $deltaChar;
            } else {
                $char += $deltaChar;
            }
            $out[] = ['line' => $line, 'char' => $char, 'length' => $length];
        }
        return $out;
    }

    public function testCapabilityAdvertisesLegendAndFullSupport(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());

        $caps = new ServerCapabilities();
        $handler->registerCapabiltiies($caps);

        self::assertIsArray($caps->semanticTokensProvider);
        self::assertArrayHasKey('legend', $caps->semanticTokensProvider);
        self::assertArrayHasKey('full', $caps->semanticTokensProvider);
        self::assertTrue($caps->semanticTokensProvider['full']);

        $legend = $caps->semanticTokensProvider['legend'];
        self::assertSame(TokenLegend::TOKEN_TYPES, $legend->tokenTypes);
        self::assertSame(TokenLegend::TOKEN_MODIFIERS, $legend->tokenModifiers);
    }

    public function testMethodsMapAdvertisesTheFullEndpoint(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        self::assertSame(
            ['textDocument/semanticTokens/full' => 'semanticTokensFull'],
            $handler->methods(),
        );
    }

    public function testUnknownDocumentReturnsEmptyTokens(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());

        // Framework-style positional call shape.
        $result = wait($handler->semanticTokensFull(['uri' => '/never-opened.xphp']));

        self::assertInstanceOf(SemanticTokens::class, $result);
        self::assertSame([], $result->data);
    }

    public function testKnownDocumentReturnsNonEmptyTokenStream(): void
    {
        // Slice 2: visitor classifies keywords, vars, comments,
        // class names, etc.  The protocol shape (SemanticTokens
        // object wrapping a packed integer array) is the same as
        // slice 1; we just have non-empty data now.  Detailed
        // classification assertions live in AstVisitorTest -- here
        // we only care that the handler delivers SOMETHING.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/box.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Box<T> {
            public T $item;
        }
        XPHP));

        $handler = $this->newHandler($workspace);

        // Framework-style positional call: HandlerMethodRunner does
        // `array_values($params)` and splats positionally, so the
        // handler receives the UNWRAPPED textDocument map as its
        // first argument.  This was the production bug fixed in the
        // post-prod-test iteration.
        $result = wait($handler->semanticTokensFull(['uri' => '/box.xphp']));

        self::assertInstanceOf(SemanticTokens::class, $result);
        self::assertNotEmpty($result->data);
        // Sanity: packed array length must be a multiple of 5 (5 ints
        // per token by LSP spec).
        self::assertSame(0, count($result->data) % 5);
    }

    public function testAcceptsWrappedParamsShapeForBackwardsCompatibility(): void
    {
        // Some callers (early tests, future shape-tolerant code paths)
        // may pass the full LSP params `{textDocument: {uri: ...}}`
        // map.  The handler's extractUri tolerates both shapes.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/box.xphp', 'xphp', 1, "<?php\nclass Foo {}"));

        $handler = $this->newHandler($workspace);

        $result = wait($handler->semanticTokensFull([
            'textDocument' => ['uri' => '/box.xphp'],
        ]));

        self::assertInstanceOf(SemanticTokens::class, $result);
        self::assertNotEmpty($result->data);
    }

    public function testMalformedParamsReturnsEmptyTokens(): void
    {
        // Defensive: if `textDocument` is missing, return empty rather than
        // throwing -- a misbehaving client shouldn't kill the handler.
        $handler = $this->newHandler(new PhpactorWorkspace());

        $result = wait($handler->semanticTokensFull([]));

        self::assertInstanceOf(SemanticTokens::class, $result);
        self::assertSame([], $result->data);
    }

    public function testTextDocumentAsWrappedObjectIsAlsoAccepted(): void
    {
        // Defensive: if some path hands the handler a stdClass at the
        // textDocument slot (instead of an array), extractUri tolerates
        // it.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/empty.xphp', 'xphp', 1, '<?php'));

        $handler = $this->newHandler($workspace);
        $textDocument = new \stdClass();
        $textDocument->uri = '/empty.xphp';

        $result = wait($handler->semanticTokensFull([
            'textDocument' => $textDocument,
        ]));

        self::assertInstanceOf(SemanticTokens::class, $result);
    }

    private function newHandler(PhpactorWorkspace $workspace): XphpSemanticTokensHandler
    {
        return new XphpSemanticTokensHandler($workspace, $this->newCache());
    }

    private function newCache(): ParsedDocumentCache
    {
        return new ParsedDocumentCache(
            new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion())),
        );
    }
}
