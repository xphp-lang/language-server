<?php

declare(strict_types=1);

namespace XPHP\Lsp\Diagnostics;

use Closure;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServer\Event\TextDocumentSaved;
use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;
use Psr\EventDispatcher\ListenerProviderInterface;

use function Amp\asyncCall;

/**
 * On `textDocument/didSave`, runs the authoritative compiler check over the whole
 * source set and publishes its diagnostics.
 *
 * Division of labour with the tolerant tier (avoids double-publishing, since
 * `publishDiagnostics` is a full replace per URI):
 *   - Results are stored in {@see AuthoritativeDiagnosticsStore}. For OPEN
 *     documents, {@see XphpDiagnosticsProvider} merges the store into what the
 *     engine publishes — so the engine stays the sole publisher of open docs and
 *     carries both tiers in one message. The save itself re-triggers that engine
 *     pass (and its dependent-broadcast), so open docs refresh against the fresh
 *     store.
 *   - For files that are NOT open, the engine never publishes, so THIS listener
 *     publishes them directly and clears them when they drop out of the set.
 *
 * The check reads from disk, so it only makes sense on save (buffer flushed).
 * Same-tick save bursts (e.g. "Save All") are coalesced to a single run via a
 * generation counter; the actual work is a synchronous {@see self::refresh()} so
 * it is unit-testable without the event loop.
 */
final class AuthoritativeDiagnosticsListener implements ListenerProviderInterface
{
    /**
     * URIs this listener published DIRECTLY (i.e. non-open files) on the last
     * refresh, so the next refresh can clear the ones that dropped out.
     *
     * @var list<string>
     */
    private array $publishedUris = [];

    private int $generation = 0;

    /**
     * @param Closure(string, ?int, list<LspDiagnostic>): void $publish
     *        Publishes (uri, version, diagnostics) — injected so tests can capture
     *        without a live client. In production this wraps
     *        `ClientApi::diagnostics()->publishDiagnostics(...)`.
     */
    public function __construct(
        private readonly DiagnosticsCheckSource $runner,
        private readonly AuthoritativeDiagnosticsStore $store,
        private readonly Closure $publish,
        private readonly ?PhpactorWorkspace $workspace = null,
    ) {
    }

    public function getListenersForEvent(object $event): iterable
    {
        if ($event instanceof TextDocumentSaved) {
            return [[$this, 'onSave']];
        }
        return [];
    }

    public function onSave(TextDocumentSaved $event): void
    {
        // The saved file anchors per-document manifest discovery in the runner.
        $fromPath = self::uriToPath($event->identifier()->uri);
        $generation = ++$this->generation;
        asyncCall(function () use ($generation, $fromPath): void {
            // A newer save arrived before this tick ran — let that one do the work.
            if ($generation !== $this->generation) {
                return;
            }
            $this->refresh($fromPath);
        });
    }

    /**
     * Run the authoritative check, update the store, publish non-open files, and
     * clear any non-open files that no longer have diagnostics. Synchronous and
     * side-effect-only — the unit-test entry point.
     */
    public function refresh(?string $fromPath = null): void
    {
        $byUri = $this->runner->run($fromPath);
        $this->store->replaceAll($byUri);

        /** @var array<string, true> $publishedNow */
        $publishedNow = [];
        foreach ($byUri as $uri => $diagnostics) {
            // Open docs are the engine's job (it merges the store); publishing
            // them here too would clobber the tolerant tier.
            if ($this->isOpen($uri)) {
                continue;
            }
            ($this->publish)($uri, null, $diagnostics);
            // @infection-ignore-all TrueValue -- read only via isset() below, which
            // is true for a `false` value too, so true->false is equivalent.
            $publishedNow[$uri] = true;
        }

        // Stale-clear: non-open files we published last time but not now.
        foreach ($this->publishedUris as $uri) {
            if (isset($publishedNow[$uri]) || $this->isOpen($uri)) {
                continue;
            }
            ($this->publish)($uri, null, []);
        }

        $this->publishedUris = array_keys($publishedNow);
    }

    private function isOpen(string $uri): bool
    {
        return $this->workspace !== null && $this->workspace->has($uri);
    }

    private static function uriToPath(string $uri): ?string
    {
        // TextDocumentIdentifier already url-decodes; just strip the scheme.
        return str_starts_with($uri, 'file://') ? substr($uri, 7) : null;
    }
}
