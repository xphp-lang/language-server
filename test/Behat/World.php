<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Phpactor\LanguageServer\Test\LanguageServerTester;
use Phpactor\LanguageServerProtocol\ClientCapabilities;
use Phpactor\LanguageServerProtocol\InitializeParams;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
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
 * One World is constructor-injected into every context of a scenario (see
 * {@see WorldArgumentResolver}); a fresh one is created per scenario/example, so
 * each gets its own tester and nothing leaks. Nothing is shared on disk except
 * the read-only PHP-stubs cache, so feature files shard across processes
 * conflict-free.
 */
final class World
{
    /** @var array<string, string> uri -> source (for needle/position lookups) */
    private array $sources = [];

    private ?LanguageServerTester $tester = null;

    /** Last response result from a When step (Location, Hover, list, WorkspaceEdit, ...). */
    private mixed $lastResponse = null;

    // ---- server lifecycle + request dispatch -------------------------------

    /** Ensure the server is initialized (the FQN index warms on Initialized). */
    public function boot(): void
    {
        $this->server();
    }

    /** Open a fixture as a real textDocument/didOpen notification. */
    public function openFile(string $uri, string $source): void
    {
        $this->sources[$uri] = $source;
        $this->server()->textDocument()->open($uri, $source);
    }

    /**
     * Send a request through the real dispatcher; store and return the typed
     * result so When steps store it and Then steps read it via {@see last()}.
     */
    public function request(string $method, mixed $params): mixed
    {
        $response = $this->server()->requestAndWait($method, $params);
        if ($response !== null && $response->error !== null) {
            $this->fail(sprintf('LSP error on %s: %s', $method, $response->error->message ?? 'unknown'));
        }
        return $this->lastResponse = $response?->result;
    }

    public function last(): mixed
    {
        return $this->lastResponse;
    }

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

    // ---- position / fixture helpers ---------------------------------------

    /**
     * Resolve a needle on a 0-indexed line to an LSP {@see Position}. Picks the
     * first occurrence that begins an identifier token and is NOT $-prefixed,
     * so `first` matches `->first()` rather than the `$first` variable.
     */
    public function positionOfNeedle(string $path, int $line, string $needle): Position
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

    public function normalizeLocation(mixed $response): ?Location
    {
        if (is_array($response)) {
            $response = $response[0] ?? null;
        }
        return $response instanceof Location ? $response : null;
    }

    public function expectLocation(): Location
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
    public function locationUris(mixed $locations): array
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
    public function textInRange(Location $location): string
    {
        $target = $this->sources[$this->stripFileScheme($location->uri)]
            ?? $this->sources[$location->uri]
            ?? throw new \RuntimeException("target doc not in fixtures: {$location->uri}");
        $map = new PositionMap($target);
        $start = $map->positionToOffset($location->range->start->line, $location->range->start->character);
        $end = $map->positionToOffset($location->range->end->line, $location->range->end->character);

        return substr($target, $start, max(0, $end - $start));
    }

    public function stripFileScheme(string $uri): string
    {
        return str_starts_with($uri, 'file://') ? substr($uri, strlen('file://')) : $uri;
    }

    public function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            $this->fail($message);
        }
    }

    public function fail(string $message): never
    {
        throw new \RuntimeException($message);
    }
}
