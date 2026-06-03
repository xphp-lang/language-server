<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\ParserFactory;
use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\DiagnosticCode;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;
use XPHP\Lsp\Diagnostics\DiagnosticTranslator;
use XPHP\Lsp\Resolver\BoundErrorCodeActionProvider;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Drives the full bound-fix chain: WorkspaceAnalyzer computes the structured
 * `data`, DiagnosticTranslator carries it to the LSP diagnostic, and
 * BoundErrorCodeActionProvider turns it into quick-fixes.
 */
final class BoundErrorCodeActionProviderTest extends TestCase
{
    private const STRINGY = <<<'PHP'
        <?php
        namespace App;
        final class Stringy implements \Stringable {
            public function __toString(): string { return ''; }
        }
        PHP;

    private const BOX = <<<'PHP'
        <?php
        namespace App;
        class Box<T: \Stringable> { public T $item; }
        PHP;

    public function testScalarConcreteOffersSwapButNotImplementInterface(): void
    {
        $actions = $this->actionsForUse([
            '/Stringy.xphp' => self::STRINGY,
            '/Box.xphp' => self::BOX,
            '/Use.xphp' => "<?php\nnamespace App;\n\$x = new Box<int>();\n",
        ]);

        $titles = self::titles($actions);
        self::assertContains('Change type argument to Stringy', $titles);
        foreach ($titles as $title) {
            self::assertStringStartsNotWith('Add implements', $title, 'no implement-interface fix for a scalar concrete');
        }

        // The swap edit replaces the offending `int` type-argument.
        $swap = self::actionTitled($actions, 'Change type argument to Stringy');
        $edit = $swap->edit->documentChanges[0]->edits[0];
        self::assertSame('Stringy', $edit->newText);
        self::assertSame(2, $edit->range->start->line, 'the `int` is on line 2 of Use.xphp');
    }

    public function testWorkspaceClassConcreteOffersBothFixes(): void
    {
        $actions = $this->actionsForUse([
            '/Stringy.xphp' => self::STRINGY,
            '/Box.xphp' => self::BOX,
            '/Money.xphp' => "<?php\nnamespace App;\nclass Money {}\n",
            '/Use.xphp' => "<?php\nnamespace App;\n\$x = new Box<Money>();\n",
        ]);

        $titles = self::titles($actions);
        self::assertContains('Change type argument to Stringy', $titles);
        self::assertContains('Add implements \\Stringable to Money', $titles);

        // The implement-interface fix is a cross-file edit on Money.xphp.
        $implement = self::actionTitled($actions, 'Add implements \\Stringable to Money');
        $change = $implement->edit->documentChanges[0];
        self::assertSame('/Money.xphp', $change->textDocument->uri);
        self::assertStringContainsString('implements \\Stringable', $change->edits[0]->newText);
    }

    public function testNoFixesWhenConcreteAlreadyImplementsViaAnotherViolation(): void
    {
        // Two type params; only the second is violated. The fix must target the
        // SECOND type-argument, not the first.
        $actions = $this->actionsForUse([
            '/Stringy.xphp' => self::STRINGY,
            '/Pair.xphp' => "<?php\nnamespace App;\nclass Pair<A, B: \\Stringable> { public A \$a; public B \$b; }\n",
            '/Use.xphp' => "<?php\nnamespace App;\n\$x = new Pair<Stringy, int>();\n",
        ]);

        $swap = self::actionTitled($actions, 'Change type argument to Stringy');
        $covered = $swap->edit->documentChanges[0]->edits[0]->range;
        // `int` is the second arg; assert the edit lands on it (not on Stringy).
        self::assertSame(2, $covered->start->line);
        // The replaced span should be 3 chars wide (`int`).
        self::assertSame(3, $covered->end->character - $covered->start->character);
    }

    /**
     * @param array<string, string> $sources
     * @return list<CodeAction>
     */
    private function actionsForUse(array $sources): array
    {
        $files = $this->parseFiles($sources);
        $diagnostics = (new WorkspaceAnalyzer())->analyze($files);
        $lspDiagnostics = [];
        foreach ($diagnostics['/Use.xphp'] as $diagnostic) {
            if ($diagnostic->code === DiagnosticCode::BoundViolation) {
                $lspDiagnostics[] = DiagnosticTranslator::toLsp($diagnostic);
            }
        }
        self::assertNotSame([], $lspDiagnostics, 'expected a bound violation on /Use.xphp');

        return (new BoundErrorCodeActionProvider())->actionsFor(
            '/Use.xphp',
            1,
            $sources['/Use.xphp'],
            $lspDiagnostics,
        );
    }

    /**
     * @param list<CodeAction> $actions
     * @return list<string>
     */
    private static function titles(array $actions): array
    {
        return array_map(static fn (CodeAction $a): string => $a->title, $actions);
    }

    /**
     * @param list<CodeAction> $actions
     */
    private static function actionTitled(array $actions, string $title): CodeAction
    {
        foreach ($actions as $action) {
            if ($action->title === $title) {
                return $action;
            }
        }
        self::fail(sprintf('no action titled "%s"; got [%s]', $title, implode(', ', self::titles($actions))));
    }

    /**
     * @param array<string, string> $sources
     * @return array<string, array{ast: list<\PhpParser\Node\Stmt>, source: string}>
     */
    private function parseFiles(array $sources): array
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $analyzer = new Analyzer($parser);
        $out = [];
        foreach ($sources as $path => $source) {
            $result = $analyzer->analyzeFile($source);
            self::assertNotNull($result->ast, "fixture {$path} should parse");
            $out[$path] = ['ast' => $result->ast, 'source' => $source];
        }
        return $out;
    }
}
