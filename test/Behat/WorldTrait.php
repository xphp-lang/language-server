<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Gherkin\Node\PyStringNode;
use Phpactor\LanguageServer\Test\LanguageServerTester;
use Phpactor\LanguageServerProtocol\ClientCapabilities;
use Phpactor\LanguageServerProtocol\DefinitionParams;
use Phpactor\LanguageServerProtocol\DocumentHighlightParams;
use Phpactor\LanguageServerProtocol\DocumentSymbolParams;
use Phpactor\LanguageServerProtocol\FoldingRangeParams;
use Phpactor\LanguageServerProtocol\HoverParams;
use Phpactor\LanguageServerProtocol\ImplementationParams;
use Phpactor\LanguageServerProtocol\InitializeParams;
use Phpactor\LanguageServerProtocol\InlayHintParams;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ReferenceContext;
use Phpactor\LanguageServerProtocol\ReferenceParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TypeDefinitionParams;
use XPHP\Lsp\LspDispatcherFactory;
use XPHP\Lsp\PositionMap;

/**
 * Shared in-memory "world" for the Behat acceptance suite.
 *
 * Scenarios drive the REAL language server end-to-end via phpactor's
 * {@see LanguageServerTester}: it builds the production {@see LspDispatcherFactory}
 * with a {@see \Phpactor\LanguageServer\Core\Server\Transmitter\TestMessageTransmitter}
 * (an in-memory buffer -- no stdio, sockets, or files), runs the real
 * initialize/ServerCapabilities handshake, and routes JSON-RPC requests through
 * the full middleware + argument-resolver stack to the real handlers. So the
 * tests exercise routing, the initialize handshake, textDocument/didOpen sync,
 * and the actual wiring -- not a re-derived copy of it.
 *
 * Each scenario gets a fresh tester (Behat builds a new context per scenario)
 * with its own transmitter; nothing is shared on disk except the read-only
 * PHP-stubs cache, so feature files shard across processes conflict-free.
 */
trait WorldTrait
{
    /** @var array<string, string> uri -> source (for needle/position lookups) */
    private array $sources = [];

    private ?LanguageServerTester $tester = null;

    /** Last response result from a When step (Location, Hover, list, WorkspaceEdit, ...). */
    private mixed $lastResponse = null;

    // ---- shared Given steps ------------------------------------------------

    /**
     * @Given the file at :path contains the following lines:
     */
    public function theFileAtContainsTheFollowingLines(string $path, PyStringNode $lines): void
    {
        $source = $lines->getRaw();
        $this->sources[$path] = $source;
        // Open as a real textDocument/didOpen notification through the server.
        $this->server()->textDocument()->open($path, $source);
    }

    /**
     * @Given the FQN index has been warmed on initialize
     */
    public function theFqnIndexHasBeenWarmedOnInitialize(): void
    {
        // The index warms on the Initialized event, which fired during the
        // initialize handshake in server(). With an empty rootPath the
        // filesystem walk is a no-op; open documents resolve live.
        $this->server();
    }

    // ---- server lifecycle + request dispatch -------------------------------

    private function server(): LanguageServerTester
    {
        if ($this->tester === null) {
            $this->tester = new LanguageServerTester(
                new LspDispatcherFactory(),
                new InitializeParams(new ClientCapabilities()),
            );
            $this->tester->initialize();
        }
        return $this->tester;
    }

    /**
     * Send a request through the real dispatcher and return the typed result.
     */
    private function request(string $method, mixed $params): mixed
    {
        $response = $this->server()->requestAndWait($method, $params);
        if ($response !== null && $response->error !== null) {
            $this->fail(sprintf('LSP error on %s: %s', $method, $response->error->message ?? 'unknown'));
        }
        return $response?->result;
    }

    // ---- generic request steps ---------------------------------------------

    /**
     * @When I request :method on :needle at line :line of :path
     */
    public function iRequestOnAtLineOf(string $method, string $needle, int $line, string $path): void
    {
        $pos = $this->positionOfNeedle($path, $line, $needle);
        $doc = new TextDocumentIdentifier($path);

        $this->lastResponse = match ($method) {
            'textDocument/definition' => $this->request($method, new DefinitionParams($doc, $pos)),
            'textDocument/typeDefinition' => $this->request($method, new TypeDefinitionParams($doc, $pos)),
            'textDocument/references' => $this->request($method, new ReferenceParams(new ReferenceContext(true), $doc, $pos)),
            'textDocument/implementation' => $this->request($method, new ImplementationParams($doc, $pos)),
            'textDocument/documentHighlight' => $this->request($method, new DocumentHighlightParams($doc, $pos)),
            'textDocument/hover' => $this->request($method, new HoverParams($doc, $pos)),
            default => throw new \RuntimeException("Unsupported position method: {$method}"),
        };
    }

    /**
     * @When I request :method for :path
     */
    public function iRequestForDocument(string $method, string $path): void
    {
        $doc = new TextDocumentIdentifier($path);

        $this->lastResponse = match ($method) {
            'textDocument/documentSymbol' => $this->request($method, new DocumentSymbolParams($doc)),
            'textDocument/foldingRange' => $this->request($method, new FoldingRangeParams($doc)),
            // The handler reads an unwrapped {uri} map (no published *Params type),
            // so send the wire shape and let PassThroughArgumentResolver deliver it.
            'textDocument/semanticTokens/full' => $this->request($method, ['textDocument' => ['uri' => $path]]),
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
        $this->lastResponse = $this->request($method, $params);
    }

    // ---- position / fixture helpers ---------------------------------------

    /**
     * Resolve a needle on a 0-indexed line to an LSP {@see Position}. Picks the
     * first occurrence that begins an identifier token and is NOT $-prefixed,
     * so `first` matches `->first()` rather than the `$first` variable.
     */
    private function positionOfNeedle(string $path, int $line, string $needle): Position
    {
        $source = $this->sources[$path] ?? throw new \RuntimeException("unknown fixture: {$path}");
        $lines = explode("\n", $source);
        if (!isset($lines[$line])) {
            throw new \RuntimeException("line {$line} out of range in {$path}");
        }
        $lineStart = 0;
        for ($i = 0; $i < $line; $i++) {
            $lineStart += strlen($lines[$i]) + 1; // +1 for the stripped "\n"
        }
        $col = $this->columnInLine($lines[$line], $needle);
        [$lspLine, $lspChar] = (new PositionMap($source))->offsetToPosition($lineStart + $col);

        return new Position($lspLine, $lspChar);
    }

    private function columnInLine(string $haystack, string $needle): int
    {
        $from = 0;
        while (($at = strpos($haystack, $needle, $from)) !== false) {
            $before = $at > 0 ? $haystack[$at - 1] : '';
            $boundary = $before === '' || !preg_match('/[A-Za-z0-9_]/', $before);
            if ($before !== '$' && $boundary) {
                return $at;
            }
            $from = $at + 1;
        }
        $first = strpos($haystack, $needle);
        if ($first === false) {
            throw new \RuntimeException("needle \"{$needle}\" not found on line");
        }
        return $first;
    }

    // ---- assertion helpers -------------------------------------------------

    private function normalizeLocation(mixed $response): ?Location
    {
        if (is_array($response)) {
            $response = $response[0] ?? null;
        }
        return $response instanceof Location ? $response : null;
    }

    private function expectLocation(): Location
    {
        $location = $this->normalizeLocation($this->lastResponse);
        $this->assert(
            $location !== null,
            'expected a Location response, got ' . get_debug_type($this->lastResponse),
        );

        return $location;
    }

    /**
     * @return list<string> uris
     */
    private function locationUris(mixed $locations): array
    {
        $this->assert(is_array($locations), 'expected a list of Locations, got ' . get_debug_type($locations));
        $uris = [];
        foreach ($locations as $loc) {
            if ($loc instanceof Location) {
                $uris[] = $loc->uri;
            }
        }
        return $uris;
    }

    /** Slice the target document by an LSP range and return the covered text. */
    private function textInRange(Location $location): string
    {
        $target = $this->sources[$this->stripFileScheme($location->uri)]
            ?? $this->sources[$location->uri]
            ?? throw new \RuntimeException("target doc not in fixtures: {$location->uri}");
        $map = new PositionMap($target);
        $start = $map->positionToOffset($location->range->start->line, $location->range->start->character);
        $end = $map->positionToOffset($location->range->end->line, $location->range->end->character);

        return substr($target, $start, max(0, $end - $start));
    }

    private function stripFileScheme(string $uri): string
    {
        return str_starts_with($uri, 'file://') ? substr($uri, strlen('file://')) : $uri;
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            $this->fail($message);
        }
    }

    private function fail(string $message): never
    {
        throw new \RuntimeException($message);
    }
}
