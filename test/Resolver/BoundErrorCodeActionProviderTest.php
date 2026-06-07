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
            '/Use.xphp' => "<?php\nnamespace App;\n\$x = new Box::<int>();\n",
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
        // Pin the exact span of the `int` arg inside `new Box::<int>` so the
        // turbofish clause-locating + segment-trim arithmetic can't drift.
        self::assertSame(strlen('$x = new Box::<'), $edit->range->start->character);
        self::assertSame(strlen('$x = new Box::<int'), $edit->range->end->character);
    }

    public function testSwapRangeTrimsWhitespacePaddingInsideClause(): void
    {
        // Whitespace padding around the offending arg must be trimmed so the
        // swap edit covers exactly `int`, not the surrounding spaces. Locks the
        // leading/trailing trim loops in the clause range finder.
        $actions = $this->actionsForUse([
            '/Stringy.xphp' => self::STRINGY,
            '/Box.xphp' => self::BOX,
            '/Use.xphp' => "<?php\nnamespace App;\n\$x = new Box::<  int  >();\n",
        ]);

        $swap = self::actionTitled($actions, 'Change type argument to Stringy');
        $range = $swap->edit->documentChanges[0]->edits[0]->range;
        self::assertSame(strlen('$x = new Box::<  '), $range->start->character);
        self::assertSame(3, $range->end->character - $range->start->character);
    }

    public function testWorkspaceClassConcreteOffersBothFixes(): void
    {
        $actions = $this->actionsForUse([
            '/Stringy.xphp' => self::STRINGY,
            '/Box.xphp' => self::BOX,
            '/Money.xphp' => "<?php\nnamespace App;\nclass Money {}\n",
            '/Use.xphp' => "<?php\nnamespace App;\n\$x = new Box::<Money>();\n",
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
            '/Use.xphp' => "<?php\nnamespace App;\n\$x = new Pair::<Stringy, int>();\n",
        ]);

        $swap = self::actionTitled($actions, 'Change type argument to Stringy');
        $covered = $swap->edit->documentChanges[0]->edits[0]->range;
        // `int` is the second arg; assert the edit lands on it (not on Stringy).
        self::assertSame(2, $covered->start->line);
        // Pin the exact column so the clause segment-split + whitespace-trim
        // arithmetic in typeArgRange can't drift. In `$x = new Pair::<Stringy, int>();`
        // the `int` arg starts at column 25.
        self::assertSame(strlen('$x = new Pair::<Stringy, '), $covered->start->character);
        self::assertSame(2, $covered->end->line);
        // The replaced span should be 3 chars wide (`int`).
        self::assertSame(3, $covered->end->character - $covered->start->character);
    }

    public function testFirstViolatedSlotIsTargetedWhenBothViolate(): void
    {
        // BOTH type args violate their bounds; the fix must target the FIRST
        // violated slot's type-argument (`int`), not the second (`bool`).
        $actions = $this->actionsForUse([
            '/Stringy.xphp' => self::STRINGY,
            '/Pair.xphp' => "<?php\nnamespace App;\nclass Pair<A: \\Stringable, B: \\Stringable> { public A \$a; public B \$b; }\n",
            '/Use.xphp' => "<?php\nnamespace App;\n\$x = new Pair::<int, bool>();\n",
        ]);

        $swap = self::actionTitled($actions, 'Change type argument to Stringy');
        $range = $swap->edit->documentChanges[0]->edits[0]->range;
        // The edit lands on the FIRST arg `int`, not the second `bool`.
        self::assertSame(strlen('$x = new Pair::<'), $range->start->character);
        self::assertSame(3, $range->end->character - $range->start->character);
    }

    public function testIntersectionBoundOffersImplementPerMissingLeaf(): void
    {
        // `Box<T : Animal & Comparable>`; the concrete `Half` implements Animal
        // but NOT Comparable -- exactly one implement fix for the missing leaf.
        $actions = $this->actionsForUse([
            '/Box.xphp' => "<?php\nnamespace App;\ninterface Animal {}\ninterface Comparable {}\nclass Box<T : Animal & Comparable> {}\n",
            '/Half.xphp' => "<?php\nnamespace App;\nclass Half implements Animal {}\n",
            '/Use.xphp' => "<?php\nnamespace App;\n\$x = new Box::<Half>();\n",
        ]);

        $titles = self::titles($actions);
        self::assertContains('Add implements \\App\\Comparable to Half', $titles, 'missing leaf gets an implement fix');
        self::assertNotContains('Add implements \\App\\Animal to Half', $titles, 'already-implemented leaf gets no fix');
    }

    public function testIntersectionBoundOffersImplementForEachMissingLeaf(): void
    {
        // The concrete implements neither leaf -- one implement fix per leaf.
        $actions = $this->actionsForUse([
            '/Box.xphp' => "<?php\nnamespace App;\ninterface Animal {}\ninterface Comparable {}\nclass Box<T : Animal & Comparable> {}\n",
            '/None.xphp' => "<?php\nnamespace App;\nclass None {}\n",
            '/Use.xphp' => "<?php\nnamespace App;\n\$x = new Box::<None>();\n",
        ]);

        $titles = self::titles($actions);
        self::assertContains('Add implements \\App\\Animal to None', $titles);
        self::assertContains('Add implements \\App\\Comparable to None', $titles);
    }

    public function testUnionBoundSuppressesImplementFix(): void
    {
        // `Box<T : Cat | Dog>`; implementing either would satisfy it, so an
        // implement fix is ambiguous and suppressed -- only the swap remains.
        $actions = $this->actionsForUse([
            '/Box.xphp' => "<?php\nnamespace App;\ninterface Cat {}\ninterface Dog {}\nclass Tabby implements Cat {}\nclass Box<T : Cat | Dog> {}\n",
            '/None.xphp' => "<?php\nnamespace App;\nclass None {}\n",
            '/Use.xphp' => "<?php\nnamespace App;\n\$x = new Box::<None>();\n",
        ]);

        $titles = self::titles($actions);
        foreach ($titles as $title) {
            self::assertStringStartsNotWith('Add implements', $title, 'union bound must not offer an implement fix');
        }
        // The swap fix is still offered (Tabby satisfies the union via Cat).
        self::assertContains('Change type argument to Tabby', $titles);
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
