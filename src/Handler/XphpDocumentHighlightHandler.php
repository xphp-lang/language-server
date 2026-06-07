<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DocumentHighlight;
use Phpactor\LanguageServerProtocol\DocumentHighlightKind;
use Phpactor\LanguageServerProtocol\DocumentHighlightParams;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Resolver\DocumentHighlightKindResolver;
use XPHP\Lsp\Resolver\ReferenceFinder;

/**
 * `textDocument/documentHighlight` handler.
 *
 * In-file highlighting of every occurrence of the symbol under the
 * cursor.  Backs PhpStorm's "Highlight Usages in File" + the editor's
 * automatic "as you move the cursor" highlighting.  Strict subset of
 * `textDocument/references` -- we run the same resolver and filter to
 * the requesting document only.
 *
 * Each occurrence is classified as `WRITE` (the symbol's declaration or
 * an assignment / lvalue) or `READ` (a use site) via
 * {@see DocumentHighlightKindResolver}, so clients that colour read vs.
 * write (e.g. VS Code) paint them distinctly.
 *
 * Available since IntelliJ Platform 2025.3.
 */
final class XphpDocumentHighlightHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ReferenceFinder $finder,
        private readonly ParsedDocumentCache $cache,
        private readonly DocumentHighlightKindResolver $kindResolver,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/documentHighlight' => 'documentHighlight',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->documentHighlightProvider = true;
    }

    /**
     * @return Promise<list<DocumentHighlight>>
     */
    public function documentHighlight(DocumentHighlightParams $params, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success([]);
        }
        $uri = $params->textDocument->uri;
        if (!$this->workspace->has($uri)) {
            return new Success([]);
        }
        $item = $this->workspace->get($uri);
        $positionMap = $this->cache->positionMap($uri, $item->version, $item->text);
        $offset = $positionMap->positionToOffset(
            $params->position->line,
            $params->position->character,
        );

        // Always include the declaration -- the user expects every
        // mention of the symbol in the file to light up, including the
        // place they put their cursor.
        //
        // `restrictToUri: $uri` confines the scan to the requesting
        // document -- documentHighlight only renders single-file
        // results, and the prior unrestricted scan was the 2026-05-27
        // prod-log 2:43 stall (walking every indexed filesystem path,
        // each triggering worse-reflection on receiver-class inference,
        // only to discard the cross-file hits below).  Cross-file
        // matches stay available through textDocument/references.
        $locations = $this->finder->findReferences($uri, $offset, true, $cancel, $uri);

        // Classify each occurrence as read/write. The location ranges are in
        // ORIGINAL-source coordinates; the resolver maps the AST's stripped
        // offsets back to original, so the keys line up.
        $targetOffsets = [];
        foreach ($locations as $location) {
            $targetOffsets[] = $positionMap->positionToOffset(
                $location->range->start->line,
                $location->range->start->character,
            );
        }
        $parsed = $this->cache->getOrParse($uri, $item->version, $item->text);
        $kindByOffset = $parsed->ast !== null
            ? $this->kindResolver->resolve($parsed->ast, $parsed->byteOffsetMap, $targetOffsets)
            : [];

        $highlights = [];
        foreach ($locations as $i => $location) {
            $highlights[] = new DocumentHighlight(
                range: $location->range,
                // Default to READ for any occurrence the classifier didn't
                // resolve (it's a use site) -- never silently drop a highlight.
                kind: $kindByOffset[$targetOffsets[$i]] ?? DocumentHighlightKind::READ,
            );
        }
        return new Success($highlights);
    }
}
