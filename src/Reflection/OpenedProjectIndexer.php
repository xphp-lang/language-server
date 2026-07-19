<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use Phpactor\LanguageServer\Event\TextDocumentOpened;
use Psr\EventDispatcher\ListenerProviderInterface;
use XPHP\Lsp\Project\XphpManifest;

/**
 * Folds a sibling project into the FQN index as its files are opened.
 *
 * The server tracks a single workspace `rootPath` (it does not read
 * `workspaceFolders`), so opening a file from a project *outside* that root —
 * e.g. `…/collections/*.xphp` while rooted at `…/xphp` — left navigation broken:
 * the sibling's sources were never indexed. On `didOpen` this discovers the
 * opened file's own project (nearest `xphp.json`, walking up) and registers its
 * declared source roots on the existing {@see FqnIndex}, so go-to-definition,
 * find-references, completion and rename resolve symbols declared there.
 *
 * Client-agnostic: it keys purely off the opened file's path (the `textDocument`
 * every client sends), never any editor-specific signal.
 */
final class OpenedProjectIndexer implements ListenerProviderInterface
{
    public function __construct(private readonly FqnIndex $fqnIndex)
    {
    }

    public function getListenersForEvent(object $event): iterable
    {
        if ($event instanceof TextDocumentOpened) {
            return [[$this, 'onOpen']];
        }
        return [];
    }

    public function onOpen(TextDocumentOpened $event): void
    {
        $this->register(self::uriToPath($event->textDocument()->uri));
    }

    /**
     * Discover the file's project and register its source roots. Returns whether
     * any new root was added (so a caller can warm the walk off-thread).
     * Synchronous and defensive — the unit-test entry point.
     */
    public function register(?string $path): bool
    {
        if ($path === null) {
            return false;
        }
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            return false;
        }
        $manifest = XphpManifest::discover($dir);
        if ($manifest === null) {
            return false;
        }
        // @infection-ignore-all UnwrapArrayValues -- registerSourceRoots iterates
        // the excluded dirs by value, so the array_values reindex is cosmetic.
        $excluded = array_values(array_filter([$manifest->outputDir(), $manifest->cacheDir()]));

        return $this->fqnIndex->registerSourceRoots($manifest->sourceRoots(), $excluded);
    }

    private static function uriToPath(string $uri): ?string
    {
        // @infection-ignore-all DecrementInteger -- 7 == strlen('file://'); an
        // off-by-one leaves a leading `//path`, which POSIX collapses to `/path`,
        // so the resolved path is unchanged.
        return str_starts_with($uri, 'file://') ? substr($uri, 7) : null;
    }
}
