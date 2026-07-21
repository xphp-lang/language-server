<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use Phpactor\LanguageServer\Event\Initialized;
use Phpactor\WorseReflection\Reflector;
use Psr\EventDispatcher\ListenerProviderInterface;
use Throwable;
use XPHP\Lsp\Stderr;

use function Amp\asyncCall;

/**
 * Listens for phpactor's `Initialized` event and asynchronously forces the
 * worse-reflection stub map (phpstorm-stubs) to build so the first
 * user-facing hover / go-to-definition doesn't pay the cold build in-band.
 *
 * Why this matters (downstream ticket
 * phpstorm-plugin/hover-latency-unindexed-native-symbols): a hover on a
 * typed variable fans out into reflections of the stdlib symbols on its
 * assignment's right-hand side.  The very first stub reflection triggers
 * {@see \Phpactor\WorseReflection\Core\SourceCodeLocator\StubSourceLocator}
 * to walk ~3000 phpstorm-stubs files and serialize an FQN -> file map to
 * disk -- a multi-second, ~512M-peak operation (see the Makefile note on
 * the behat stub-cache pre-warm).  Paid in-band, it lands the hover
 * response well outside PhpStorm's ~200-300ms hover-cancel window and the
 * popup never paints.  Building it off the `Initialized` event moves that
 * cost off the first hover; once the map is serialized on disk subsequent
 * sessions skip the build entirely.
 *
 * Warm path: reflect a single global stdlib function (`strlen`).  Functions
 * resolve only through the stub locator (not the InternalLocator, which
 * covers a handful of fundamental interfaces), so this reliably drives the
 * stub map build.  Any failure (stubs genuinely absent, reflection error)
 * is swallowed -- warming is best-effort and must never destabilise the
 * session.
 *
 * Mirrors {@see FqnIndexWarmer}: same `Initialized` hook, same
 * `Amp\asyncCall` dispatch so the warm-up runs off the synchronous
 * initialize handshake.  Registered in `LspDispatcherFactory::create`
 * unconditionally; when stubs aren't bundled the warm reflection simply
 * throws and is swallowed (logged as "skipped"), so there's no need to
 * gate the registration.
 */
class StubCacheWarmer implements ListenerProviderInterface
{
    /**
     * A global stdlib function guaranteed to live in phpstorm-stubs and to
     * resolve exclusively via the stub locator, so reflecting it forces the
     * stub map build.
     */
    private const WARM_FUNCTION = 'strlen';

    public function __construct(private readonly Reflector $reflector)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function getListenersForEvent(object $event): iterable
    {
        if ($event instanceof Initialized) {
            return [[$this, 'warm']];
        }
        return [];
    }

    public function warm(Initialized $initialized): void
    {
        asyncCall(function (): void {
            try {
                $this->reflector->reflectFunction(self::WARM_FUNCTION);
                Stderr::write("[xphp-lsp warmer] stub-cache warmed\n");
            } catch (Throwable $e) {
                // Best-effort: a stubs-less build or a reflection hiccup must
                // not take the session down.  The first real hover will still
                // work (just paying the cold cost it would have paid anyway).
                Stderr::write(sprintf(
                    "[xphp-lsp warmer] stub-cache warm skipped: %s\n",
                    $e->getMessage(),
                ));
            }
        });
    }
}
