<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Analyzer;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\DiagnosticCode;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * V2 argument-type checking: instance method calls, static calls, free
 * function calls, and the "simple locals" inference that lets a `$var`
 * assigned from a literal / `new` flow into both argument and receiver
 * typing.
 */
final class CallArgumentCheckerTest extends TestCase
{
    public function testFlagsScalarLiteralPassedToInstanceMethod(): void
    {
        $diagnostics = $this->checkWorkspace([
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class User {
                public function rename(string $name): void {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $u = new User();
            $u->rename(42);
            PHP,
        ]);

        $diags = self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ArgumentMismatch);
        self::assertCount(1, $diags);
        self::assertStringContainsString('App\\User::rename()', $diags[0]->message);
        self::assertStringContainsString('expects string, got int', $diags[0]->message);
    }

    public function testAcceptsCorrectInstanceMethodArgument(): void
    {
        $diagnostics = $this->checkWorkspace([
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class User {
                public function rename(string $name): void {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $u = new User();
            $u->rename('alice');
            PHP,
        ]);

        self::assertSame([], self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ArgumentMismatch));
    }

    public function testFlagsMismatchOnGenericInstanceMethod(): void
    {
        // Collection<User>::add(T $item) -> with T=User, passing a Tag
        // (an unrelated class) is a TypeError.
        $diagnostics = $this->checkWorkspace([
            '/Collection.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Collection<T> {
                public function add(T $item): void {}
            }
            PHP,
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class User {}
            PHP,
            '/Tag.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class Tag {}
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $c = new Collection<User>();
            $c->add(new Tag());
            PHP,
        ]);

        $diags = self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ArgumentMismatch);
        self::assertCount(1, $diags);
        self::assertStringContainsString('App\\User', $diags[0]->message);
        self::assertStringContainsString('App\\Tag', $diags[0]->message);
    }

    public function testAcceptsCorrectGenericInstanceMethodArgument(): void
    {
        $diagnostics = $this->checkWorkspace([
            '/Collection.xphp' => <<<'PHP'
            <?php
            namespace App;
            class Collection<T> {
                public function add(T $item): void {}
            }
            PHP,
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class User {}
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $c = new Collection<User>();
            $c->add(new User());
            PHP,
        ]);

        self::assertSame([], self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ArgumentMismatch));
    }

    public function testFlagsScalarLiteralPassedToStaticMethod(): void
    {
        $diagnostics = $this->checkWorkspace([
            '/Factory.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class Factory {
                public static function make(string $key): void {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            Factory::make(42);
            PHP,
        ]);

        $diags = self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ArgumentMismatch);
        self::assertCount(1, $diags);
        self::assertStringContainsString('App\\Factory::make()', $diags[0]->message);
        self::assertStringContainsString('expects string, got int', $diags[0]->message);
    }

    public function testFlagsScalarLiteralPassedToFreeFunction(): void
    {
        $diagnostics = $this->checkWorkspace([
            '/greet.xphp' => <<<'PHP'
            <?php
            namespace App;
            function greet(string $name): void {}
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            greet(42);
            PHP,
        ]);

        $diags = self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ArgumentMismatch);
        self::assertCount(1, $diags);
        self::assertStringContainsString('App\\greet()', $diags[0]->message);
        self::assertStringContainsString('expects string, got int', $diags[0]->message);
    }

    public function testFlagsLocallyAssignedScalarVariable(): void
    {
        // Simple-locals: `$n = 42;` makes `$n` an int; passing it to a
        // `string` param is a mismatch.
        $diagnostics = $this->checkWorkspace([
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class User {
                public function rename(string $name): void {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $u = new User();
            $n = 42;
            $u->rename($n);
            PHP,
        ]);

        $diags = self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ArgumentMismatch);
        self::assertCount(1, $diags);
        self::assertStringContainsString('expects string, got int', $diags[0]->message);
    }

    public function testSkipsVariableAssignedFromNonInferableExpression(): void
    {
        // `$n = compute();` -> not inferable -> the arg is skipped.
        $diagnostics = $this->checkWorkspace([
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class User {
                public function rename(string $name): void {}
            }
            function compute(): int { return 1; }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $u = new User();
            $n = compute();
            $u->rename($n);
            PHP,
        ]);

        self::assertSame([], self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ArgumentMismatch));
    }

    public function testSkipsReassignedVariable(): void
    {
        // `$n` assigned twice -> ambiguous -> dropped from the binding
        // map -> the arg is skipped (no false positive).
        $diagnostics = $this->checkWorkspace([
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class User {
                public function rename(string $name): void {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $u = new User();
            $n = 42;
            $n = 'later';
            $u->rename($n);
            PHP,
        ]);

        self::assertSame([], self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ArgumentMismatch));
    }

    public function testSkipsMethodCallOnUnknownReceiver(): void
    {
        // Receiver type can't be inferred (param, not a local `new`) ->
        // the whole call is skipped.
        $diagnostics = $this->checkWorkspace([
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class User {
                public function rename(string $name): void {}
            }
            final class Caller {
                public function go(User $u): void {
                    $u->rename(42);
                }
            }
            PHP,
        ]);

        self::assertSame([], self::filterByCode($diagnostics['/User.xphp'], DiagnosticCode::ArgumentMismatch));
    }

    public function testPositionsDiagnosticOnTheBadArgument(): void
    {
        $diagnostics = $this->checkWorkspace([
            '/User.xphp' => <<<'PHP'
            <?php
            namespace App;
            final class User {
                public function rename(string $name): void {}
            }
            PHP,
            '/Use.xphp' => <<<'PHP'
            <?php
            namespace App;
            $u = new User();
            $u->rename(42);
            PHP,
        ]);

        $diag = self::filterByCode($diagnostics['/Use.xphp'], DiagnosticCode::ArgumentMismatch)[0];
        // `$u->rename(42);` is on line 3 (0-indexed): <?php=0, namespace=1,
        // `$u = new User();`=2, the call=3.
        self::assertSame(3, $diag->startLine);
        // `42` is 2 chars wide.
        self::assertSame(2, $diag->endCharacter - $diag->startCharacter);
    }

    /**
     * @return list<\XPHP\Lsp\Analyzer\Diagnostic>
     */
    private static function filterByCode(array $diagnostics, DiagnosticCode $code): array
    {
        return array_values(array_filter($diagnostics, static fn ($d): bool => $d->code === $code));
    }

    /**
     * @param array<string, string> $sources
     * @return array<string, list<\XPHP\Lsp\Analyzer\Diagnostic>>
     */
    private function checkWorkspace(array $sources): array
    {
        $files = $this->parseFiles($sources);
        return (new WorkspaceAnalyzer())->analyze($files);
    }

    /**
     * Mirror the prod path: the per-file Analyzer does NOT run nikic's
     * NameResolver before handing ASTs to the WorkspaceAnalyzer.
     *
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
