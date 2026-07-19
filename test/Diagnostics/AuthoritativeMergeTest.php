<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Diagnostics;

use Amp\CancellationTokenSource;
use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;
use XPHP\Lsp\Diagnostics\AuthoritativeDiagnosticsStore;
use XPHP\Lsp\Diagnostics\XphpDiagnosticsProvider;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

/**
 * Verifies the provider folds the authoritative store into an open document's
 * published diagnostics — the mechanism that lets the tolerant per-keystroke
 * tier and the on-save compiler tier reach the client in a single publish
 * (a publish is a full replace, so a second publisher would clobber).
 */
final class AuthoritativeMergeTest extends TestCase
{
    private const URI = 'file:///project/merge.xphp';

    private function authoritative(int $line, string $code): LspDiagnostic
    {
        $d = new LspDiagnostic(
            range: new Range(new Position($line, 0), new Position($line, 10)),
            message: 'grounded closure mismatch',
        );
        $d->code = $code;
        $d->severity = 1;
        $d->source = 'xphp';
        return $d;
    }

    /**
     * @return list<LspDiagnostic>
     */
    private function lintWithStore(TextDocumentItem $doc, PhpactorWorkspace $workspace, AuthoritativeDiagnosticsStore $store): array
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $provider = new XphpDiagnosticsProvider(
            $cache,
            new WorkspaceAnalyzer(),
            $workspace,
            new FqnIndex($workspace, $cache, $parser, ''),
            null,
            null,
            $store,
        );
        $result = wait($provider->provideDiagnostics($doc, (new CancellationTokenSource())->getToken()));
        return is_array($result) ? array_values($result) : [];
    }

    private function openCleanDoc(PhpactorWorkspace $workspace): TextDocumentItem
    {
        $doc = new TextDocumentItem(self::URI, 'xphp', 1, "<?php\nnamespace App;\nclass Box<T> { public T \$item; }\n");
        $workspace->open($doc);
        return $doc;
    }

    public function testAuthoritativeDiagnosticsAreMergedIntoAnOpenDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $doc = $this->openCleanDoc($workspace);
        $store = new AuthoritativeDiagnosticsStore();
        $store->replaceAll([self::URI => [$this->authoritative(2, 'xphp.closure_conformance')]]);

        $codes = array_map(
            static fn (LspDiagnostic $d): string => (string) $d->code,
            $this->lintWithStore($doc, $workspace, $store),
        );

        self::assertContains('xphp.closure_conformance', $codes, 'the on-save compiler diagnostic is surfaced on an open doc');
    }

    public function testMergeDedupesByLineAndCode(): void
    {
        $workspace = new PhpactorWorkspace();
        $doc = $this->openCleanDoc($workspace);
        $store = new AuthoritativeDiagnosticsStore();
        // Two diagnostics at the SAME line + code — only one should survive.
        $store->replaceAll([self::URI => [
            $this->authoritative(2, 'xphp.closure_conformance'),
            $this->authoritative(2, 'xphp.closure_conformance'),
        ]]);

        $conformance = array_values(array_filter(
            $this->lintWithStore($doc, $workspace, $store),
            static fn (LspDiagnostic $d): bool => (string) $d->code === 'xphp.closure_conformance',
        ));

        self::assertCount(1, $conformance);
    }

    public function testNoStoreLeavesTolerantOutputUnchanged(): void
    {
        $workspace = new PhpactorWorkspace();
        $doc = $this->openCleanDoc($workspace);

        // A clean doc with no store wired stays clean (regression guard: the
        // merge path must be inert when no authoritative pass has run).
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $provider = new XphpDiagnosticsProvider(
            $cache,
            new WorkspaceAnalyzer(),
            $workspace,
            new FqnIndex($workspace, $cache, $parser, ''),
        );
        $result = wait($provider->provideDiagnostics($doc, (new CancellationTokenSource())->getToken()));

        self::assertSame([], is_array($result) ? array_values($result) : []);
    }
}
