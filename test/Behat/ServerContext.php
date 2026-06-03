<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Phpactor\LanguageServerProtocol\DefinitionParams;
use Phpactor\LanguageServerProtocol\DocumentHighlightParams;
use Phpactor\LanguageServerProtocol\DocumentSymbolParams;
use Phpactor\LanguageServerProtocol\FoldingRangeParams;
use Phpactor\LanguageServerProtocol\HoverParams;
use Phpactor\LanguageServerProtocol\ImplementationParams;
use Phpactor\LanguageServerProtocol\InlayHintParams;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ReferenceContext;
use Phpactor\LanguageServerProtocol\ReferenceParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TypeDefinitionParams;

/**
 * Cross-theme steps: the fixture Givens and the generic request dispatchers.
 * The per-scenario {@see World} is constructor-injected (see {@see WorldExtension}).
 */
final class ServerContext implements Context
{
    public function __construct(private readonly World $world)
    {
    }

    // ---- fixture Givens ----------------------------------------------------

    /**
     * @Given the file at :path contains the following lines:
     */
    public function theFileAtContainsTheFollowingLines(string $path, PyStringNode $lines): void
    {
        $this->world->openFile($path, $lines->getRaw());
    }

    /**
     * @Given the FQN index has been warmed on initialize
     */
    public function theFqnIndexHasBeenWarmedOnInitialize(): void
    {
        // The index warms on the Initialized event, which fires during the
        // initialize handshake. With an empty rootPath the filesystem walk is a
        // no-op; open documents resolve live.
        $this->world->boot();
    }

    // ---- generic request dispatchers ---------------------------------------

    /**
     * @When I request :method on :needle at line :line of :path
     */
    public function iRequestOnAtLineOf(string $method, string $needle, int $line, string $path): void
    {
        $pos = $this->world->positionOfNeedle($path, $line, $needle);
        $doc = new TextDocumentIdentifier($path);

        match ($method) {
            'textDocument/definition' => $this->world->request($method, new DefinitionParams($doc, $pos)),
            'textDocument/typeDefinition' => $this->world->request($method, new TypeDefinitionParams($doc, $pos)),
            'textDocument/references' => $this->world->request($method, new ReferenceParams(new ReferenceContext(true), $doc, $pos)),
            'textDocument/implementation' => $this->world->request($method, new ImplementationParams($doc, $pos)),
            'textDocument/documentHighlight' => $this->world->request($method, new DocumentHighlightParams($doc, $pos)),
            'textDocument/hover' => $this->world->request($method, new HoverParams($doc, $pos)),
            default => throw new \RuntimeException("Unsupported position method: {$method}"),
        };
    }

    /**
     * @When I request :method for :path
     */
    public function iRequestForDocument(string $method, string $path): void
    {
        $doc = new TextDocumentIdentifier($path);

        match ($method) {
            'textDocument/documentSymbol' => $this->world->request($method, new DocumentSymbolParams($doc)),
            'textDocument/foldingRange' => $this->world->request($method, new FoldingRangeParams($doc)),
            // The handler reads an unwrapped {uri} map (no published *Params type),
            // so send the wire shape and let PassThroughArgumentResolver deliver it.
            'textDocument/semanticTokens/full' => $this->world->request($method, ['textDocument' => ['uri' => $path]]),
            default => throw new \RuntimeException("Unsupported document method: {$method}"),
        };
    }

    /**
     * @When I request :method for the visible range of :path
     */
    public function iRequestForTheVisibleRangeOf(string $method, string $path): void
    {
        if ($method !== 'textDocument/inlayHint') {
            throw new \RuntimeException("Unsupported range method: {$method}");
        }
        $params = new InlayHintParams(
            new TextDocumentIdentifier($path),
            new Range(new Position(0, 0), new Position(99999, 0)),
        );
        $this->world->request($method, $params);
    }
}
