<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use Phpactor\LanguageServer\Event\TextDocumentUpdated;
use Phpactor\WorseReflection\Core\Cache;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Flushes the shared worse-reflection {@see Cache} whenever an open
 * document changes.
 *
 * The reflection cache (enabled in {@see ReflectorFactory} to collapse the
 * heavy intra-hover reflection fan-out -- see that class's docblock) is
 * keyed by symbol NAME with no source-version component.  Without this
 * listener, a reflection of a class the user just edited in an open buffer
 * would keep being served from the cache until its TTL lapsed, so hover /
 * completion / go-to-definition would show pre-edit members.
 *
 * A `TextDocumentUpdated` event fires on every `didChange` (dispatched by
 * {@see \XPHP\Lsp\Handler\XphpTextDocumentHandler}).  Purging the whole
 * cache on it is correct and cheap: the purge lands BETWEEN user actions,
 * never inside a single hover's reflection fan-out, so the intra-hover
 * memoization that makes hover fast is fully preserved -- only cross-action
 * reuse of an edited symbol is (correctly) discarded.
 */
final class ReflectionCachePurger implements ListenerProviderInterface
{
    public function __construct(private readonly Cache $cache)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function getListenersForEvent(object $event): iterable
    {
        if ($event instanceof TextDocumentUpdated) {
            return [[$this, 'purge']];
        }
        return [];
    }

    public function purge(TextDocumentUpdated $event): void
    {
        $this->cache->purge();
    }
}
