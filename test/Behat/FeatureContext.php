<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DefinitionParams;
use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\HoverParams;
use Phpactor\LanguageServerProtocol\InlayHint;
use Phpactor\LanguageServerProtocol\InlayHintParams;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\WorkspaceSymbols;
use XPHP\Lsp\Handler\XphpDefinitionHandler;
use XPHP\Lsp\Handler\XphpHoverHandler;
use XPHP\Lsp\Handler\XphpInlayHintHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\CompositeClassLikeLookup;
use XPHP\Lsp\Resolver\FilesystemClassLikeLookup;
use XPHP\Lsp\Resolver\GenericParamRegistry;
use XPHP\Lsp\Resolver\GenericResolver;
use XPHP\Lsp\Resolver\PhpDefinitionResolver;
use XPHP\Lsp\Resolver\PhpHoverResolver;
use XPHP\Lsp\Resolver\ReferenceFinder;
use XPHP\Lsp\Resolver\WorkspaceClassLikeLookup;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

/**
 * Drives the real LSP handlers against a fully in-memory workspace.
 *
 * Every fixture declared in a scenario's `Background` is opened as a
 * {@see TextDocumentItem} in a fresh {@see PhpactorWorkspace} -- nothing is
 * written to disk. Behat constructs a new instance of this context per
 * scenario, so the workspace + handler stack are isolated; combined with the
 * absence of any shared mutable state (no temp files, DB, or sockets) the
 * scenarios are safe to shard across processes and run in parallel.
 *
 * The handler stack mirrors {@see \XPHP\Lsp\LspDispatcherFactory} with an empty
 * rootPath so the filesystem index finds nothing and only the in-memory open
 * documents resolve.
 */
final class FeatureContext implements Context
{
    /** @var array<string, string> path -> source (for needle/position lookups) */
    private array $sources = [];

    /**
     * One workspace per scenario; every fixture is opened into it, so scenarios
     * that declare several files exercise a multi-document workspace.
     */
    private readonly PhpactorWorkspace $workspace;
    private bool $handlersBuilt = false;
    private ?XphpDefinitionHandler $definitionHandler = null;
    private ?XphpHoverHandler $hoverHandler = null;
    private ?XphpInlayHintHandler $inlayHandler = null;

    /** Last definition/hover/inlay response. */
    private mixed $lastResponse = null;

    public function __construct()
    {
        // Behat instantiates a fresh context per scenario, so this workspace is
        // isolated to one scenario.
        $this->workspace = new PhpactorWorkspace();
    }

    /**
     * @Given the file at :path contains the following lines:
     */
    public function theFileAtContainsTheFollowingLines(string $path, PyStringNode $lines): void
    {
        $source = $lines->getRaw();
        $this->sources[$path] = $source;
        // Open the fixture into the shared workspace. Multiple files accumulate
        // here; the handler stack resolves against the live workspace, so every
        // open document is visible regardless of declaration order.
        $this->workspace->open(new TextDocumentItem($path, 'xphp', 1, $source));
    }

    /**
     * @Given the FQN index has been warmed on initialize
     */
    public function theFqnIndexHasBeenWarmedOnInitialize(): void
    {
        $this->buildHandlers();
    }

    /**
     * @When I request :method on :needle at line :line of :path
     */
    public function iRequestOnAtLineOf(string $method, string $needle, int $line, string $path): void
    {
        $this->buildHandlers();
        [$pos, ] = $this->positionOfNeedle($path, $line, $needle);
        $params = new TextDocumentIdentifier($path);

        $this->lastResponse = match ($method) {
            'textDocument/definition' => wait($this->definitionHandler->definition(new DefinitionParams($params, $pos))),
            'textDocument/hover' => wait($this->hoverHandler->hover(new HoverParams($params, $pos))),
            default => throw new \RuntimeException("Unsupported method: {$method}"),
        };
    }

    /**
     * @When I request :method for the visible range of :path
     */
    public function iRequestForTheVisibleRangeOf(string $method, string $path): void
    {
        $this->buildHandlers();
        if ($method !== 'textDocument/inlayHint') {
            throw new \RuntimeException("Unsupported range method: {$method}");
        }
        $params = new InlayHintParams(
            new TextDocumentIdentifier($path),
            new Range(new Position(0, 0), new Position(99999, 0)),
        );
        $this->lastResponse = wait($this->inlayHandler->inlayHint($params));
    }

    /**
     * @Then the response points to :path
     */
    public function theResponsePointsTo(string $path): void
    {
        $location = $this->expectLocation();
        $this->assert(
            $location->uri === $path,
            sprintf('expected definition to point to "%s", got "%s"', $path, $location->uri),
        );
    }

    /**
     * @Then the target range covers the :name class name
     */
    public function theTargetRangeCoversTheClassName(string $name): void
    {
        $this->assertRangeCovers($name);
    }

    /**
     * @Then the target range covers the :name method declaration
     */
    public function theTargetRangeCoversTheMethodDeclaration(string $name): void
    {
        $this->assertRangeCovers($name);
    }

    /**
     * @Then the hover contents describe the class :fqn
     */
    public function theHoverContentsDescribeTheClass(string $fqn): void
    {
        $this->assertHoverContains($fqn);
    }

    /**
     * @Then the hover contents show the substituted signature :sig
     */
    public function theHoverContentsShowTheSubstitutedSignature(string $sig): void
    {
        $this->assertHoverContains($sig);
    }

    /**
     * @Then an inlay hint :label is rendered after :var on line :line
     */
    public function anInlayHintIsRenderedAfterOnLine(string $label, string $var, int $line): void
    {
        $hints = $this->lastResponse;
        $this->assert(is_array($hints), 'expected an inlay-hint list response');

        $labels = [];
        foreach ($hints as $hint) {
            if (!$hint instanceof InlayHint) {
                continue;
            }
            $hintLabel = is_string($hint->label) ? $hint->label : '';
            $labels[] = sprintf('%s@L%d', $hintLabel, $hint->position->line);
            if ($hintLabel === $label && $hint->position->line === $line) {
                return;
            }
        }

        $this->fail(sprintf(
            'no inlay hint "%s" on line %d (after "%s"); got: [%s]',
            $label,
            $line,
            $var,
            implode(', ', $labels) ?: '<none>',
        ));
    }

    // ---- harness internals -------------------------------------------------

    private function buildHandlers(): void
    {
        if ($this->handlersBuilt) {
            return;
        }

        // The handler stack resolves against the live workspace -- it walks the
        // open documents on every query -- so it is safe to build once even if
        // more files are opened afterwards.
        $workspace = $this->workspace;

        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        // Empty rootPath: no filesystem walk. Only the open documents resolve.
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, '');
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            '',
            ReflectorFactory::defaultStubPath(),
            ReflectorFactory::defaultCacheDir(),
            $fqnIndex,
        ))->build();
        $genericParams = new GenericParamRegistry($fqnIndex);
        $classLikeLookup = new CompositeClassLikeLookup(
            new WorkspaceClassLikeLookup($workspace, $cache),
            new FilesystemClassLikeLookup($fqnIndex),
        );
        $genericResolver = new GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        $phpDefinitionResolver = new PhpDefinitionResolver($workspace, $parser, $reflector, $cache, $genericResolver);
        $phpHoverResolver = new PhpHoverResolver($workspace, $parser, $reflector, $genericParams, $genericResolver);
        $referenceFinder = new ReferenceFinder($workspace, $cache, $fqnIndex, $parser, $reflector, $genericResolver);

        $this->definitionHandler = new XphpDefinitionHandler(
            $workspace,
            $cache,
            new WorkspaceSymbols($workspace, $cache),
            $fqnIndex,
            $referenceFinder,
            $phpDefinitionResolver,
        );
        $this->hoverHandler = new XphpHoverHandler($workspace, $cache, $phpHoverResolver);
        $this->inlayHandler = new XphpInlayHintHandler($workspace, $cache, $genericResolver);
        $this->handlersBuilt = true;
    }

    /**
     * Resolve a needle on a 0-indexed line to an LSP {@see Position}. Picks the
     * first occurrence that begins an identifier token and is NOT $-prefixed,
     * so `first` matches `->first()` rather than the `$first` variable.
     *
     * @return array{0: Position, 1: int} position and absolute byte offset
     */
    private function positionOfNeedle(string $path, int $line, string $needle): array
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

        $haystack = $lines[$line];
        $col = $this->columnInLine($haystack, $needle);
        $byte = $lineStart + $col;
        [$lspLine, $lspChar] = (new PositionMap($source))->offsetToPosition($byte);

        return [new Position($lspLine, $lspChar), $byte];
    }

    private function columnInLine(string $haystack, string $needle): int
    {
        $from = 0;
        while (($at = strpos($haystack, $needle, $from)) !== false) {
            $before = $at > 0 ? $haystack[$at - 1] : '';
            $isIdentBoundary = $before === '' || !preg_match('/[A-Za-z0-9_]/', $before);
            if ($before !== '$' && $isIdentBoundary) {
                return $at;
            }
            $from = $at + 1;
        }
        // Fall back to the first occurrence if no clean boundary matched.
        $first = strpos($haystack, $needle);
        if ($first === false) {
            throw new \RuntimeException("needle \"{$needle}\" not found on line");
        }
        return $first;
    }

    private function expectLocation(): Location
    {
        $response = $this->lastResponse;
        if (is_array($response)) {
            $response = $response[0] ?? null;
        }
        $this->assert(
            $response instanceof Location,
            'expected a Location response, got ' . get_debug_type($this->lastResponse),
        );

        return $response;
    }

    private function assertRangeCovers(string $name): void
    {
        $location = $this->expectLocation();
        $target = $this->sources[$location->uri]
            ?? throw new \RuntimeException("target doc not in fixtures: {$location->uri}");
        $map = new PositionMap($target);
        $start = $map->positionToOffset($location->range->start->line, $location->range->start->character);
        $end = $map->positionToOffset($location->range->end->line, $location->range->end->character);
        $covered = substr($target, $start, max(0, $end - $start));

        $this->assert(
            $covered === $name,
            sprintf('expected target range to cover "%s", but it covers "%s"', $name, $covered),
        );
    }

    private function assertHoverContains(string $needle): void
    {
        $hover = $this->lastResponse;
        $this->assert($hover instanceof Hover, 'expected a Hover response, got ' . get_debug_type($hover));
        $contents = $hover->contents;
        $text = $contents instanceof MarkupContent ? $contents->value : (is_string($contents) ? $contents : '');
        $this->assert(
            str_contains($text, $needle),
            sprintf('expected hover contents to contain "%s", got: %s', $needle, $text === '' ? '<empty>' : $text),
        );
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
