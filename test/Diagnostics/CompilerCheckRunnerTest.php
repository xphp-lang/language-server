<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Diagnostics;

use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Diagnostics\CompilerCheckRunner;

/**
 * Tests the authoritative diagnostics tier: the runner drives the upstream
 * compiler's own `check()` over a whole source set and maps its diagnostics to
 * LSP shape. This is the tier that catches the grounded, call-argument
 * closure-conformance error the tolerant per-keystroke pass cannot.
 */
final class CompilerCheckRunnerTest extends TestCase
{
    private function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixture/authoritative/' . $name;
    }

    /**
     * @return list<LspDiagnostic>
     */
    private function diagnosticsFor(string $fixture): array
    {
        $byUri = (new CompilerCheckRunner($this->fixture($fixture)))->run();
        $flat = [];
        foreach ($byUri as $diags) {
            foreach ($diags as $d) {
                $flat[] = $d;
            }
        }
        return $flat;
    }

    /**
     * @param list<LspDiagnostic> $diagnostics
     */
    private static function codes(array $diagnostics): array
    {
        return array_map(static fn (LspDiagnostic $d): string => (string) $d->code, $diagnostics);
    }

    public function testRejectFixtureSurfacesCallArgumentClosureConformance(): void
    {
        $byUri = (new CompilerCheckRunner($this->fixture('reject')))->run();

        self::assertCount(1, $byUri, 'diagnostics routed to exactly the one source file');
        $uri = array_key_first($byUri);
        self::assertIsString($uri);
        self::assertStringEndsWith('/Use.xphp', $uri);
        self::assertStringStartsWith('file://', $uri);

        $conformance = array_values(array_filter(
            $byUri[$uri],
            static fn (LspDiagnostic $d): bool => (string) $d->code === 'xphp.closure_conformance',
        ));
        self::assertCount(1, $conformance, 'the call-argument closure conformance error is reported');
        $d = $conformance[0];
        self::assertSame(1, $d->severity, 'closure conformance is an Error');
        self::assertSame('xphp', $d->source);
        self::assertStringContainsString('int is not wider than', $d->message);

        // Full-line range on the exact offending line (0-based 20 == the
        // `$box->map::<string>(...)` call). Pinning the line + length guards the
        // 1-based→0-based conversion and the line-length computation.
        $sourceLine = explode("\n", (string) file_get_contents($this->fixture('reject') . '/Use.xphp'))[20];
        self::assertSame(20, $d->range->start->line);
        self::assertSame(20, $d->range->end->line);
        self::assertSame(0, $d->range->start->character);
        self::assertSame(strlen(rtrim($sourceLine, "\r")), $d->range->end->character);
    }

    public function testWarningSeverityIsMapped(): void
    {
        // A variance edge over an undeclared type arg is a Warning, not an Error —
        // exercises the non-Error arm of the severity mapping.
        $warnings = array_values(array_filter(
            $this->diagnosticsFor('warn'),
            static fn (LspDiagnostic $d): bool => $d->severity === 2,
        ));
        self::assertNotEmpty($warnings, 'a Warning-severity diagnostic is surfaced as LSP severity 2');
    }

    public function testCrlfLineEndingIsNotIncludedInTheRange(): void
    {
        $byUri = (new CompilerCheckRunner($this->fixture('crlf')))->run();
        $diag = null;
        foreach ($byUri as $diags) {
            foreach ($diags as $d) {
                if ((string) $d->code === 'xphp.closure_conformance') {
                    $diag = $d;
                }
            }
        }
        self::assertNotNull($diag);

        $rawLine = explode("\n", (string) file_get_contents($this->fixture('crlf') . '/Use.xphp'))[$diag->range->start->line];
        self::assertStringEndsWith("\r", $rawLine, 'fixture is genuinely CRLF');
        // The range must stop at the last real character, not the trailing CR.
        self::assertSame(strlen(rtrim($rawLine, "\r")), $diag->range->end->character);
    }

    public function testWholeSetIsFedSoSplitFixtureStillFires(): void
    {
        // The violating call lives in Use.xphp; Box/Book are declared in a
        // SEPARATE Lib.xphp. Because the runner feeds the whole source set, the
        // compiler can specialize Box<Book> and catch the closure mismatch.
        self::assertContains('xphp.closure_conformance', self::codes($this->diagnosticsFor('split')));
    }

    public function testClosedWorldContractUseSiteAloneDoesNotGround(): void
    {
        // Same use-site WITHOUT its Box/Book declarations in the set: the compiler
        // cannot ground Box<Book>, so the grounded closure check never runs. This
        // is why the runner must be handed the whole source set, not one file.
        self::assertNotContains('xphp.closure_conformance', self::codes($this->diagnosticsFor('use_only')));
    }

    public function testDiagnosticsInSeparateFilesAreKeptDistinct(): void
    {
        // Two files each carry their own violation; the result must key both URIs
        // (guards against collapsing the per-file grouping to a single entry).
        $byUri = (new CompilerCheckRunner($this->fixture('multi')))->run();
        self::assertCount(2, $byUri);
        foreach ($byUri as $diags) {
            self::assertNotEmpty($diags);
        }
    }

    public function testSyntheticSpecializationPathsAreNotPublished(): void
    {
        // A grounded SELF-call closure conformance is reported by the compiler
        // against a synthetic "<specialized:...>" filepath that maps to no real
        // source line. It must never be surfaced as a URI (a phantom Problems
        // entry pointing at an unopenable path).
        $byUri = (new CompilerCheckRunner($this->fixture('selfcall')))->run();
        $synthetic = array_values(array_filter(
            array_keys($byUri),
            static fn ($uri): bool => str_contains((string) $uri, '<specialized'),
        ));
        self::assertSame([], $synthetic, 'synthetic specialization paths must never be published as URIs');
        // Every routed URI is a real on-disk file.
        foreach (array_keys($byUri) as $uri) {
            self::assertFileExists(str_replace('file://', '', (string) $uri));
        }
    }

    public function testLargeSourceSetsAreSkippedToProtectTheEventLoop(): void
    {
        // The `multi` fixture has two files; a cap of 1 must skip the whole pass
        // rather than run the synchronous compiler over the set (which would block
        // the LSP event loop on a real large project).
        $byUri = (new CompilerCheckRunner($this->fixture('multi'), maxFiles: 1))->run();
        self::assertSame([], $byUri);

        // Boundary: a cap EQUAL to the file count admits the set (skip only when
        // strictly greater) — the two-file fixture runs under maxFiles: 2.
        self::assertCount(2, (new CompilerCheckRunner($this->fixture('multi'), maxFiles: 2))->run());
    }

    public function testCleanFixtureReturnsNoDiagnostics(): void
    {
        self::assertSame([], $this->diagnosticsFor('clean'));
    }

    public function testEmptyRootPathIsInert(): void
    {
        // The behat harness (and any un-rooted client) boots with an empty
        // rootPath; the authoritative pass must be a no-op then.
        self::assertSame([], (new CompilerCheckRunner(''))->run());
    }

    public function testNonExistentRootIsInert(): void
    {
        self::assertSame([], (new CompilerCheckRunner('/no/such/directory/really'))->run());
    }
}
