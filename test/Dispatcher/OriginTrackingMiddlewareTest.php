<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Dispatcher;

use Amp\Promise;
use Amp\Success;
use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Middleware\Middleware;
use Phpactor\LanguageServer\Core\Middleware\RequestHandler;
use Phpactor\LanguageServer\Core\Rpc\Message;
use Phpactor\LanguageServer\Core\Rpc\NotificationMessage;
use Phpactor\LanguageServer\Core\Rpc\RequestMessage;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Dispatcher\OriginTrackingMiddleware;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class OriginTrackingMiddlewareTest extends TestCase
{
    public function testSetsOriginFromTextDocumentUri(): void
    {
        $index = $this->fqnIndex();
        $middleware = new OriginTrackingMiddleware($index);

        wait($middleware->process(
            new RequestMessage('1', 'textDocument/definition', [
                'textDocument' => ['uri' => 'file:///proj/src/Use.xphp'],
                'position' => ['line' => 1, 'character' => 2],
            ]),
            $this->terminal(),
        ));

        self::assertSame('file:///proj/src/Use.xphp', $index->currentOrigin());
    }

    public function testClearsOriginForRequestsWithoutTextDocument(): void
    {
        $index = $this->fqnIndex();
        $index->withOrigin('file:///stale.xphp');
        $middleware = new OriginTrackingMiddleware($index);

        wait($middleware->process(
            new RequestMessage('2', 'workspace/symbol', ['query' => 'Foo']),
            $this->terminal(),
        ));

        self::assertNull($index->currentOrigin(), 'workspace-wide requests must not inherit a stale anchor');
    }

    public function testSetsOriginFromNotification(): void
    {
        $index = $this->fqnIndex();
        $middleware = new OriginTrackingMiddleware($index);

        wait($middleware->process(
            new NotificationMessage('textDocument/didOpen', [
                'textDocument' => ['uri' => 'file:///proj/A.xphp', 'text' => '<?php'],
            ]),
            $this->terminal(),
        ));

        self::assertSame('file:///proj/A.xphp', $index->currentOrigin());
    }

    public function testDelegatesToTheRestOfTheChain(): void
    {
        $index = $this->fqnIndex();
        $middleware = new OriginTrackingMiddleware($index);

        $result = wait($middleware->process(
            new RequestMessage('3', 'textDocument/hover', ['textDocument' => ['uri' => 'file:///x.xphp']]),
            $this->terminal('handled'),
        ));

        self::assertSame('handled', $result);
    }

    private function fqnIndex(): FqnIndex
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        return new FqnIndex(new PhpactorWorkspace(), $cache, $parser, '');
    }

    /**
     * A RequestHandler whose single (terminal) middleware returns $value.
     */
    private function terminal(mixed $value = null): RequestHandler
    {
        $terminal = new class($value) implements Middleware {
            public function __construct(private readonly mixed $value)
            {
            }

            public function process(Message $request, RequestHandler $handler): Promise
            {
                return new Success($this->value);
            }
        };
        return new RequestHandler([$terminal]);
    }
}
