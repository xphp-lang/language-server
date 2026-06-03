<?php

declare(strict_types=1);

namespace XPHP\Lsp\Dispatcher;

use Amp\Promise;
use Phpactor\LanguageServer\Core\Middleware\Middleware;
use Phpactor\LanguageServer\Core\Middleware\RequestHandler;
use Phpactor\LanguageServer\Core\Rpc\Message;
use Phpactor\LanguageServer\Core\Rpc\NotificationMessage;
use Phpactor\LanguageServer\Core\Rpc\RequestMessage;
use XPHP\Lsp\Reflection\FqnIndex;

/**
 * Anchors FQN resolution to the document a request is about.
 *
 * When several files in the workspace declare the same FQN (common in monorepo
 * / fixture trees), the index resolves to the declaration NEAREST the
 * requesting file. But the resolution chain runs deep -- through the static
 * GenericResolver inference and the worse-reflection `SourceCodeLocator`, both
 * of which carry no document context. Rather than thread an origin parameter
 * through every layer, this middleware reads `textDocument.uri` off each
 * inbound request and parks it on {@see FqnIndex::withOrigin()} for the
 * duration of handling; the index consults it as the proximity anchor.
 *
 * Requests without a `textDocument` (workspace/symbol, initialize, …) clear the
 * anchor, so origin-less / workspace-wide resolutions keep their global
 * behavior. Safe because the server dispatches requests one-at-a-time on the
 * event loop and the xphp handlers resolve synchronously.
 */
final class OriginTrackingMiddleware implements Middleware
{
    public function __construct(private readonly FqnIndex $fqnIndex)
    {
    }

    public function process(Message $message, RequestHandler $handler): Promise
    {
        $this->fqnIndex->withOrigin(self::originUri($message));

        return $handler->handle($message);
    }

    private static function originUri(Message $message): ?string
    {
        if (!$message instanceof RequestMessage && !$message instanceof NotificationMessage) {
            return null;
        }
        $params = $message->params;
        if (!is_array($params)) {
            return null;
        }
        $textDocument = $params['textDocument'] ?? null;
        if (is_array($textDocument) && isset($textDocument['uri']) && is_string($textDocument['uri'])) {
            return $textDocument['uri'];
        }
        return null;
    }
}
