<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\SemanticTokens;
use Phpactor\LanguageServerProtocol\SemanticTokensLegend;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\SemanticTokens\AstVisitor;
use XPHP\Lsp\Handler\SemanticTokens\Encoder;
use XPHP\Lsp\Handler\SemanticTokens\TokenLegend;

/**
 * `textDocument/semanticTokens/full` handler.
 *
 * Walks the xphp AST and returns the file's tokens as the
 * delta-encoded integer array LSP defines.  The visitor classifies
 * each AST node into a {@see TokenLegend::TOKEN_TYPES} entry
 * (`keyword`, `variable`, `string`, `typeParameter`, etc.).  Both
 * PhpStorm (via the IntelliJ Platform LSP API) and VS Code (via
 * `vscode-languageclient`) render the tokens with their built-in
 * "semantic" coloring -- no per-editor theme work required.
 *
 * The handler advertises the capability and dispatches to
 * {@see AstVisitor}, which emits the file's tokens: keywords,
 * variables, numbers, strings, comments, declaration names
 * (class / interface / enum / method / function / property /
 * parameter), and `typeParameter` for every xphp generic `T` --
 * both inside `<...>` clauses and reified-`T` uses (`new T()`,
 * `instanceof T`, `T::class`) in generic class bodies.
 *
 * Remaining limitation: token `length` is byte-counted, so a
 * multi-byte identifier is off by the UTF-8/UTF-16 delta (LSP
 * wants UTF-16 code units).  ASCII identifiers -- the vast
 * majority -- are exact.  See {@see AstVisitor::emit}.
 *
 * Server-capability shape: an ARRAY value, not a class instance.
 * The phpactor JSON serializer null-strips empty options classes,
 * producing `[]` which the IntelliJ LSP4J client rejects with
 * `Unexpected token BEGIN_ARRAY: expected BEGIN_OBJECT` -- the same
 * bug {@see XphpHoverHandler} documents for `hoverProvider`.  An
 * inline array shaped `{legend: {...}, full: true}` serializes
 * unambiguously as an object.
 *
 * @see https://microsoft.github.io/language-server-protocol/specifications/lsp/3.17/specification/#textDocument_semanticTokens
 */
final class XphpSemanticTokensHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/semanticTokens/full' => 'semanticTokensFull',
        ];
    }

    // Note the phpactor-side typo (sic).
    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->semanticTokensProvider = [
            'legend' => new SemanticTokensLegend(
                TokenLegend::TOKEN_TYPES,
                TokenLegend::TOKEN_MODIFIERS,
            ),
            'full' => true,
        ];
    }

    /**
     * Params shape from the wire: `{textDocument: {uri: string}}`.
     *
     * Phpactor's `LanguageSeverProtocolParamsResolver` only auto-binds
     * classes named `Phpactor\LanguageServerProtocol\*Params`, and
     * `SemanticTokensParams` isn't published in their library.  The
     * resolver chain falls through to `PassThroughArgumentResolver`,
     * which returns `$request->params` to be passed to the handler --
     * but `HandlerMethodRunner` does `array_values($args)` and
     * `$handler->$method(...$args)` to splat them positionally.  So
     * for params `{textDocument: {uri: ...}}` the handler receives
     * the INNER `{uri: ...}` map as its first positional argument,
     * not the wrapper.  We document that explicitly with the param
     * name `$textDocument` and read `uri` from it directly.
     *
     * @param  array<string, mixed> $textDocument the unwrapped LSP TextDocumentIdentifier
     * @return Promise<SemanticTokens>
     */
    public function semanticTokensFull(array $textDocument, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success(new SemanticTokens([]));
        }
        $uri = self::extractUri($textDocument);
        if ($uri === null || !$this->workspace->has($uri)) {
            return new Success(new SemanticTokens([]));
        }
        $item = $this->workspace->get($uri);
        $result = $this->cache->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null) {
            return new Success(new SemanticTokens([]));
        }
        if ($cancel !== null && $cancel->isRequested()) {
            // Parse completed but cancel arrived before the visitor
            // could run -- bail before the (potentially expensive)
            // tree walk.
            return new Success(new SemanticTokens([]));
        }

        $visitor = new AstVisitor(
            $this->cache->positionMap($uri, $item->version, $item->text),
            $result->byteOffsetMap,
            $item->text,
        );
        $specs = $visitor->visit($result->ast);
        $packed = Encoder::encode($specs);

        return new Success(new SemanticTokens($packed));
    }

    /**
     * Read `uri` from the passed-in `TextDocumentIdentifier` map.
     * Tolerates both the unwrapped shape `{uri: ...}` (the production
     * path -- HandlerMethodRunner splats positional args) and the
     * wrapped shape `{textDocument: {uri: ...}}` (some test paths
     * that hand the handler the full params map directly).
     *
     * @param array<string, mixed> $params
     */
    private static function extractUri(array $params): ?string
    {
        if (isset($params['uri']) && is_string($params['uri'])) {
            return $params['uri'];
        }
        $textDocument = $params['textDocument'] ?? null;
        if (is_array($textDocument)) {
            $uri = $textDocument['uri'] ?? null;
            return is_string($uri) ? $uri : null;
        }
        if (is_object($textDocument) && isset($textDocument->uri) && is_string($textDocument->uri)) {
            return $textDocument->uri;
        }
        return null;
    }
}
