<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Gherkin\Node\PyStringNode;
use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DefinitionParams;
use Phpactor\LanguageServerProtocol\DocumentHighlightParams;
use Phpactor\LanguageServerProtocol\HoverParams;
use Phpactor\LanguageServerProtocol\ImplementationParams;
use Phpactor\LanguageServerProtocol\InlayHintParams;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ReferenceContext;
use Phpactor\LanguageServerProtocol\ReferenceParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServerProtocol\TypeDefinitionParams;

use function Amp\Promise\wait;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;
use XPHP\Lsp\Diagnostics\XphpDiagnosticsProvider;
use XPHP\Lsp\Handler\WorkspaceSymbols;
use XPHP\Lsp\Handler\XphpCallHierarchyHandler;
use XPHP\Lsp\Handler\XphpCodeActionHandler;
use XPHP\Lsp\Handler\XphpCodeLensHandler;
use XPHP\Lsp\Handler\XphpCompletionHandler;
use XPHP\Lsp\Handler\XphpCompletionResolveHandler;
use XPHP\Lsp\Handler\XphpDefinitionHandler;
use XPHP\Lsp\Handler\XphpDocumentHighlightHandler;
use XPHP\Lsp\Handler\XphpDocumentSymbolHandler;
use XPHP\Lsp\Handler\XphpFoldingRangeHandler;
use XPHP\Lsp\Handler\XphpHoverHandler;
use XPHP\Lsp\Handler\XphpImplementationHandler;
use XPHP\Lsp\Handler\XphpInlayHintHandler;
use XPHP\Lsp\Handler\XphpReferencesHandler;
use XPHP\Lsp\Handler\XphpRenameHandler;
use XPHP\Lsp\Handler\XphpSemanticTokensHandler;
use XPHP\Lsp\Handler\XphpSignatureHelpHandler;
use XPHP\Lsp\Handler\XphpTypeDefinitionHandler;
use XPHP\Lsp\Handler\XphpTypeHierarchyHandler;
use XPHP\Lsp\Handler\XphpWillRenameFilesHandler;
use XPHP\Lsp\Handler\XphpWorkspaceSymbolHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\CompletionIndex;
use XPHP\Lsp\Resolver\CompositeClassLikeLookup;
use XPHP\Lsp\Resolver\DiagnosticCodeActionProvider;
use XPHP\Lsp\Resolver\FilesystemClassLikeLookup;
use XPHP\Lsp\Resolver\GenericParamRegistry;
use XPHP\Lsp\Resolver\GenericResolver;
use XPHP\Lsp\Resolver\ImportCodeActionProvider;
use XPHP\Lsp\Resolver\NamespaceMoveProvider;
use XPHP\Lsp\Resolver\OptimizeImportsCodeActionProvider;
use XPHP\Lsp\Resolver\PhpCompletionResolver;
use XPHP\Lsp\Resolver\PhpDefinitionResolver;
use XPHP\Lsp\Resolver\PhpHoverResolver;
use XPHP\Lsp\Resolver\ReferenceFinder;
use XPHP\Lsp\Resolver\RenameProvider;
use XPHP\Lsp\Resolver\WorkspaceClassLikeLookup;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Shared in-memory "world" for the Behat acceptance suite: the workspace, the
 * full handler stack, the fixture-loading Given steps, and assertion helpers.
 *
 * The handler stack mirrors {@see \XPHP\Lsp\LspDispatcherFactory} with an empty
 * rootPath, so only the open documents resolve -- nothing touches the
 * filesystem. Behat builds a fresh context per scenario, so the workspace is
 * isolated; combined with the absence of shared mutable state the suite is
 * safe to shard across processes.
 */
trait WorldTrait
{
    /** @var array<string, string> path -> source (for needle/position lookups) */
    private array $sources = [];

    private PhpactorWorkspace $workspace;
    private bool $handlersBuilt = false;

    /** @var array<string, object> handler key -> handler instance */
    private array $handlers = [];

    private ?XphpDiagnosticsProvider $diagnosticsProvider = null;

    /** Last response from a When step (Location, Hover, list, WorkspaceEdit, ...). */
    private mixed $lastResponse = null;

    public function __construct()
    {
        // Fresh per scenario -- Behat instantiates a new context each time.
        $this->workspace = new PhpactorWorkspace();
    }

    // ---- shared Given steps ------------------------------------------------

    /**
     * @Given the file at :path contains the following lines:
     */
    public function theFileAtContainsTheFollowingLines(string $path, PyStringNode $lines): void
    {
        $source = $lines->getRaw();
        $this->sources[$path] = $source;
        $this->workspace->open(new TextDocumentItem($path, 'xphp', 1, $source));
    }

    /**
     * @Given the FQN index has been warmed on initialize
     */
    public function theFqnIndexHasBeenWarmedOnInitialize(): void
    {
        $this->buildHandlers();
    }

    // ---- generic request steps ---------------------------------------------

    /**
     * Position-based requests. Dispatches by LSP method name and stores the
     * raw response for a Then step to assert.
     *
     * @When I request :method on :needle at line :line of :path
     */
    public function iRequestOnAtLineOf(string $method, string $needle, int $line, string $path): void
    {
        $pos = $this->positionOfNeedle($path, $line, $needle);
        $doc = new TextDocumentIdentifier($path);

        $this->lastResponse = match ($method) {
            'textDocument/definition' => wait($this->handler('definition')->definition(new DefinitionParams($doc, $pos))),
            'textDocument/typeDefinition' => wait($this->handler('typeDefinition')->typeDefinition(new TypeDefinitionParams($doc, $pos))),
            'textDocument/references' => wait($this->handler('references')->references(new ReferenceParams(new ReferenceContext(true), $doc, $pos))),
            'textDocument/implementation' => wait($this->handler('implementation')->implementation(new ImplementationParams($doc, $pos))),
            'textDocument/documentHighlight' => wait($this->handler('documentHighlight')->documentHighlight(new DocumentHighlightParams($doc, $pos))),
            'textDocument/hover' => wait($this->handler('hover')->hover(new HoverParams($doc, $pos))),
            default => throw new \RuntimeException("Unsupported position method: {$method}"),
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
        $this->lastResponse = wait($this->handler('inlay')->inlayHint($params));
    }

    // ---- world construction ------------------------------------------------

    private function buildHandlers(): void
    {
        if ($this->handlersBuilt) {
            return;
        }

        $workspace = $this->workspace;
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        // Empty rootPath: no filesystem walk. Only open documents resolve.
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
        $workspaceSymbols = new WorkspaceSymbols($workspace, $cache);
        $completionIndex = new CompletionIndex($workspaceSymbols, ReflectorFactory::defaultStubPath());
        $phpCompletionResolver = new PhpCompletionResolver(
            $workspace,
            $parser,
            $reflector,
            $completionIndex,
            $cache,
            $genericParams,
            $genericResolver,
        );
        $renameProvider = new RenameProvider($workspace, $referenceFinder, $fqnIndex, false);

        $this->handlers = [
            'definition' => new XphpDefinitionHandler(
                $workspace,
                $cache,
                $workspaceSymbols,
                $fqnIndex,
                $referenceFinder,
                $phpDefinitionResolver,
            ),
            'typeDefinition' => new XphpTypeDefinitionHandler($phpDefinitionResolver),
            'references' => new XphpReferencesHandler($workspace, $referenceFinder),
            'implementation' => new XphpImplementationHandler($workspace, $cache, $parser, $fqnIndex),
            'documentSymbol' => new XphpDocumentSymbolHandler($workspace, $cache),
            'workspaceSymbol' => new XphpWorkspaceSymbolHandler($fqnIndex),
            'documentHighlight' => new XphpDocumentHighlightHandler($workspace, $referenceFinder),
            'callHierarchy' => new XphpCallHierarchyHandler($workspace, $cache, $fqnIndex, $parser),
            'typeHierarchy' => new XphpTypeHierarchyHandler($workspace, $cache, $parser, $fqnIndex),
            'rename' => new XphpRenameHandler($workspace, $renameProvider),
            'codeAction' => new XphpCodeActionHandler(
                $workspace,
                new ImportCodeActionProvider($fqnIndex, $cache),
                new DiagnosticCodeActionProvider(),
                new OptimizeImportsCodeActionProvider($cache),
            ),
            'codeLens' => new XphpCodeLensHandler($workspace, $cache, $referenceFinder),
            'willRename' => new XphpWillRenameFilesHandler(
                $workspace,
                $cache,
                $parser,
                $renameProvider,
                new NamespaceMoveProvider($workspace, $cache, $fqnIndex, $parser),
            ),
            'hover' => new XphpHoverHandler($workspace, $cache, $phpHoverResolver),
            'signatureHelp' => new XphpSignatureHelpHandler($workspace, $cache, $parser, $reflector),
            'inlay' => new XphpInlayHintHandler($workspace, $cache, $genericResolver),
            'folding' => new XphpFoldingRangeHandler($workspace, $cache),
            'semanticTokens' => new XphpSemanticTokensHandler($workspace, $cache),
            'completion' => new XphpCompletionHandler($workspace, $workspaceSymbols, $phpCompletionResolver, $fqnIndex, $reflector),
            'completionResolve' => new XphpCompletionResolveHandler($reflector),
        ];
        $this->diagnosticsProvider = new XphpDiagnosticsProvider($cache, new WorkspaceAnalyzer(), $workspace, $fqnIndex);
        $this->handlersBuilt = true;
    }

    private function handler(string $key): object
    {
        $this->buildHandlers();
        return $this->handlers[$key] ?? throw new \RuntimeException("no handler: {$key}");
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
     * @param list<Location> $locations
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
