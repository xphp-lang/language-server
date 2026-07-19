<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Diagnostics;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Diagnostics\AuthoritativeDiagnosticsListener;
use XPHP\Lsp\Diagnostics\AuthoritativeDiagnosticsStore;
use XPHP\Lsp\Diagnostics\DiagnosticsCheckSource;

/**
 * Tests the on-save listener's publish/stale-clear/open-doc logic against a
 * canned {@see DiagnosticsCheckSource}, so the compiler itself is out of scope
 * (that's {@see CompilerCheckRunnerTest}). Exercises the synchronous
 * `refresh()` — the body of the async `onSave` hook.
 */
final class AuthoritativeDiagnosticsListenerTest extends TestCase
{
    private const URI = 'file:///project/Use.xphp';

    private function diag(): LspDiagnostic
    {
        $d = new LspDiagnostic(
            range: new Range(new Position(0, 0), new Position(0, 3)),
            message: 'closure literal does not conform',
        );
        $d->code = 'xphp.closure_conformance';
        $d->severity = 1;
        $d->source = 'xphp';
        return $d;
    }

    /**
     * @param list<array<string, list<LspDiagnostic>>> $queue results returned by successive run() calls
     */
    private function source(array $queue): DiagnosticsCheckSource
    {
        return new class($queue) implements DiagnosticsCheckSource {
            /** @param list<array<string, list<LspDiagnostic>>> $queue */
            public function __construct(private array $queue)
            {
            }

            public function run(?string $fromPath = null): array
            {
                return array_shift($this->queue) ?? [];
            }
        };
    }

    public function testRefreshPublishesNonOpenFileAndPopulatesStore(): void
    {
        $store = new AuthoritativeDiagnosticsStore();
        $published = [];
        $listener = new AuthoritativeDiagnosticsListener(
            $this->source([[self::URI => [$this->diag()]]]),
            $store,
            function (string $uri, ?int $version, array $diagnostics) use (&$published): void {
                $published[] = [$uri, $version, $diagnostics];
            },
            null, // no workspace => every file is "not open"
        );

        $listener->refresh();

        self::assertCount(1, $published);
        self::assertSame(self::URI, $published[0][0]);
        self::assertNull($published[0][1], 'non-open files publish with a null version');
        self::assertCount(1, $published[0][2]);
        self::assertCount(1, $store->get(self::URI), 'store holds the authoritative diagnostics');
    }

    public function testStaleDiagnosticsAreClearedOnNextRefresh(): void
    {
        $store = new AuthoritativeDiagnosticsStore();
        $published = [];
        $listener = new AuthoritativeDiagnosticsListener(
            // First save: an error. Second save: fixed (empty set).
            $this->source([[self::URI => [$this->diag()]], []]),
            $store,
            function (string $uri, ?int $version, array $diagnostics) use (&$published): void {
                $published[] = [$uri, $version, $diagnostics];
            },
            null,
        );

        $listener->refresh();
        $listener->refresh();

        self::assertCount(2, $published);
        self::assertSame(self::URI, $published[1][0]);
        self::assertSame([], $published[1][2], 'a fixed file gets an empty publish to clear its squiggles');
        self::assertSame([], $store->get(self::URI));
    }

    public function testOpenDocumentIsNotPublishedDirectly(): void
    {
        // Open documents are published by the engine (which merges the store);
        // publishing them here too would clobber the tolerant tier.
        $store = new AuthoritativeDiagnosticsStore();
        $published = [];
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(self::URI, 'xphp', 1, '<?php'));

        $listener = new AuthoritativeDiagnosticsListener(
            $this->source([[self::URI => [$this->diag()]]]),
            $store,
            function (string $uri, ?int $version, array $diagnostics) use (&$published): void {
                $published[] = [$uri, $version, $diagnostics];
            },
            $workspace,
        );

        $listener->refresh();

        self::assertSame([], $published, 'open docs are left to the engine');
        self::assertCount(1, $store->get(self::URI), 'but the store still holds them for the merge');
    }

    public function testOnlyReactsToSaveEvents(): void
    {
        $listener = new AuthoritativeDiagnosticsListener(
            $this->source([]),
            new AuthoritativeDiagnosticsStore(),
            static function (): void {
            },
            null,
        );

        $saved = new \Phpactor\LanguageServer\Event\TextDocumentSaved(
            new \Phpactor\LanguageServerProtocol\TextDocumentIdentifier(self::URI),
            null,
        );
        self::assertEquals(
            [[$listener, 'onSave']],
            [...$listener->getListenersForEvent($saved)],
            'a save event binds the onSave handler',
        );
        self::assertSame([], [...$listener->getListenersForEvent(new \stdClass())], 'an unrelated event yields nothing');
    }

    public function testNonOpenFileIsPublishedEvenAfterAnOpenOne(): void
    {
        // The publish loop must SKIP open docs and keep going — not stop — so a
        // non-open file listed after an open one is still published.
        $openUri = 'file:///project/Open.xphp';
        $closedUri = 'file:///project/Closed.xphp';
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem($openUri, 'xphp', 1, '<?php'));

        $published = [];
        $listener = new AuthoritativeDiagnosticsListener(
            // Open URI first, closed URI second — insertion order preserved.
            $this->source([[$openUri => [$this->diag()], $closedUri => [$this->diag()]]]),
            new AuthoritativeDiagnosticsStore(),
            function (string $uri, ?int $version, array $diagnostics) use (&$published): void {
                $published[] = $uri;
            },
            $workspace,
        );

        $listener->refresh();

        self::assertSame([$closedUri], $published, 'the closed file is published; the open one is skipped');
    }

    public function testPersistingFileIsNotClearedOnRefresh(): void
    {
        // A non-open file that still has diagnostics on the next pass must be
        // re-published with them, never cleared.
        $published = [];
        $listener = new AuthoritativeDiagnosticsListener(
            $this->source([[self::URI => [$this->diag()]], [self::URI => [$this->diag()]]]),
            new AuthoritativeDiagnosticsStore(),
            function (string $uri, ?int $version, array $diagnostics) use (&$published): void {
                $published[] = $diagnostics;
            },
            null,
        );

        $listener->refresh();
        $listener->refresh();

        self::assertCount(2, $published);
        self::assertCount(1, $published[1], 'second publish carries the diagnostic, not an empty clear');
    }

    public function testStaleClearSkipsPersistingFilesButStillClearsDroppedOnes(): void
    {
        // publishedUris after pass 1 = [persist, dropped] (insertion order). On
        // pass 2 `persist` still has diagnostics (skipped in the clear loop) and
        // `dropped` does not — the loop must keep going past `persist` and clear
        // `dropped`, not stop at the first skip.
        $persist = 'file:///project/Persist.xphp';
        $dropped = 'file:///project/Dropped.xphp';
        $cleared = [];
        $listener = new AuthoritativeDiagnosticsListener(
            $this->source([
                [$persist => [$this->diag()], $dropped => [$this->diag()]],
                [$persist => [$this->diag()]],
            ]),
            new AuthoritativeDiagnosticsStore(),
            function (string $uri, ?int $version, array $diagnostics) use (&$cleared): void {
                if ($diagnostics === []) {
                    $cleared[] = $uri;
                }
            },
            null,
        );

        $listener->refresh();
        $listener->refresh();

        self::assertSame([$dropped], $cleared, 'the dropped file is cleared even though a persisting file precedes it');
    }
}
