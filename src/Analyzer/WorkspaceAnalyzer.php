<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use RuntimeException;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeHierarchy;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Workspace-level analyzer that drives the existing Registry + TypeHierarchy machinery
 * across every parsed file and converts each thrown RuntimeException into a per-file
 * Diagnostic. Per-file Analyzer handles syntax errors; this one catches:
 *  - duplicate template declarations across files
 *  - hash collisions
 *  - bound violations (the highest-value diagnostic surface)
 *
 * The walk is manual (not via RegistryCollector) because we need to track *which file*
 * each Name/ClassLike came from so the diagnostic lands on the right URI. RegistryCollector
 * does the same work for the compiler — see src/Transpiler/Monomorphize/RegistryCollector.php
 * for the canonical version we're mirroring here.
 */
final readonly class WorkspaceAnalyzer
{
    /**
     * @param array<string, array{ast: list<Node\Stmt>, source: string}> $files keyed by URI/path
     * @param array<string, list<Node\Stmt>>                              $hierarchyAsts AST-only entries that
     *        enrich the bound-check hierarchy AND register their template definitions so
     *        `Registry::validateBounds` can find them. NOT walked for instantiations: any diagnostics
     *        the definition pass would produce on these URIs (e.g. duplicate-template against an open
     *        file that already won) are routed into a throwaway sink. Source isn't needed since the
     *        PositionMap for these entries is degenerate and unused.
     * @return array<string, list<Diagnostic>> diagnostics keyed by URI/path
     */
    public function analyze(array $files, array $hierarchyAsts = []): array
    {
        $diagnosticsByFile = array_fill_keys(array_keys($files), []);

        $astPerFile = [];
        foreach ($files as $path => $entry) {
            $astPerFile[$path] = $entry['ast'];
        }
        foreach ($hierarchyAsts as $uri => $ast) {
            if (!isset($astPerFile[$uri])) {
                $astPerFile[$uri] = $ast;
            }
        }
        $hierarchy = TypeHierarchy::fromAstPerFile($astPerFile);
        $registry = new Registry(hierarchy: $hierarchy);

        // Duplicate-template diagnostics. A duplicate is a property of ALL the
        // colliding declarations, not of iteration order, so we flag every open
        // file that re-declares a template (each diagnostic naming the others).
        // This makes the duplicate surface on whichever file the editor pulls:
        // the pull provider forces the current file first, which previously made
        // it the "canonical" (clean) one and hid the duplicate. Only open files
        // ($files) are considered -- filesystem copies live in $hierarchyAsts, so
        // an open file is never flagged as a duplicate of its own on-disk copy.
        $declarationsByFqn = [];
        foreach ($files as $path => $entry) {
            $positionMap = new PositionMap($entry['source']);
            foreach (self::collectTemplateDeclarations($entry['ast']) as $decl) {
                $declarationsByFqn[$decl['fqn']][] = $decl + ['path' => $path, 'positionMap' => $positionMap];
            }
        }
        foreach ($declarationsByFqn as $fqn => $declarations) {
            if (count($declarations) < 2) {
                continue;
            }
            foreach ($declarations as $index => $decl) {
                $others = [];
                foreach ($declarations as $otherIndex => $other) {
                    if ($otherIndex !== $index) {
                        $others[] = $other['path'];
                    }
                }
                $diagnosticsByFile[$decl['path']][] = self::buildDefinitionDiagnostic(
                    $decl['positionMap'],
                    $decl['name'],
                    $decl['line'],
                    sprintf('Generic template "%s" is already declared (also in %s).', $fqn, implode(', ', $others)),
                );
            }
        }

        // Register every definition so the bound-check pass below can resolve
        // templates. Open files first -- their declarations win the registry on a
        // collision; the duplicate throw is swallowed since it's already been
        // reported above. Filesystem-only definitions are registered silently so
        // Registry::validateBounds finds templates whose defining file isn't open.
        foreach ($files as $path => $entry) {
            $this->recordDefinitions($entry['ast'], $registry, $path);
        }
        foreach ($hierarchyAsts as $uri => $ast) {
            if (isset($files[$uri])) {
                continue;
            }
            $this->recordDefinitions($ast, $registry, $uri);
        }

        // Second pass: instantiations. Bound violations fire here.
        foreach ($files as $path => $entry) {
            $positionMap = new PositionMap($entry['source']);
            $this->walkInstantiations($entry['ast'], $registry, $positionMap, $diagnosticsByFile[$path]);
        }

        // Third pass: constructor argument-type checking (V1 of the
        // post-monomorphization arg type checker).  Catches the class
        // of bugs where `new C<T>(…)` or plain `new C(…)` is called
        // with an arg whose static type can't satisfy the (substituted)
        // ctor param's declared type -- a runtime TypeError waiting
        // to happen.
        $argChecks = (new ConstructorArgumentChecker())->check($files, $hierarchy);
        foreach ($argChecks as $path => $diags) {
            foreach ($diags as $diag) {
                $diagnosticsByFile[$path][] = $diag;
            }
        }

        return $diagnosticsByFile;
    }

    /**
     * Register every generic-template declaration into the registry so the
     * bound-check pass can resolve templates. Duplicate-declaration throws are
     * swallowed -- they are surfaced as diagnostics by the dedicated cross-file
     * pass in {@see analyze()}, and the registry keeps the first registration.
     *
     * @param list<Node\Stmt> $ast
     */
    private function recordDefinitions(array $ast, Registry $registry, string $sourceFile): void
    {
        $visitor = new class($registry, $sourceFile) extends NodeVisitorAbstract {
            public function __construct(
                private readonly Registry $registry,
                private readonly string $sourceFile,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (!$node instanceof ClassLike || $node->name === null) {
                    return null;
                }
                $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (!is_array($params) || $params === [] || !is_string($fqn)) {
                    return null;
                }
                try {
                    $this->registry->recordDefinition($fqn, $node->name->toString(), $params, $node, $this->sourceFile);
                } catch (RuntimeException) {
                    // Duplicate -- already reported by the cross-file pass; the
                    // registry keeps the first registration.
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }

    /**
     * Collect every generic-template declaration (FQN + name node) in a file,
     * for the cross-file duplicate-detection pass.
     *
     * @param list<Node\Stmt> $ast
     * @return list<array{fqn: string, name: \PhpParser\Node\Identifier, line: int}>
     */
    private static function collectTemplateDeclarations(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<array{fqn: string, name: \PhpParser\Node\Identifier, line: int}> */
            public array $declarations = [];

            public function enterNode(Node $node): null
            {
                if (!$node instanceof ClassLike || $node->name === null) {
                    return null;
                }
                $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (!is_array($params) || $params === [] || !is_string($fqn)) {
                    return null;
                }
                $this->declarations[] = ['fqn' => $fqn, 'name' => $node->name, 'line' => $node->getStartLine()];
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->declarations;
    }

    /**
     * Build a Definition diagnostic squiggling the class-name span (falling back
     * to the full line when byte offsets are missing).
     */
    private static function buildDefinitionDiagnostic(
        PositionMap $positionMap,
        \PhpParser\Node\Identifier $identifier,
        int $fallbackNikicLine,
        string $message,
    ): Diagnostic {
        if ($identifier->getStartFilePos() >= 0) {
            [$sl, $sc, $el, $ec] = $positionMap->rangeFromOffsets(
                $identifier->getStartFilePos(),
                $identifier->getEndFilePos() + 1,
            );
        } else {
            [$sl, $sc, $el, $ec] = $positionMap->fullLineRangeFromNikic($fallbackNikicLine);
        }
        return new Diagnostic($sl, $sc, $el, $ec, $message, code: DiagnosticCode::Definition);
    }

    /**
     * @param list<Node\Stmt> $ast
     * @param list<Diagnostic> $diagnostics
     */
    private function walkInstantiations(
        array $ast,
        Registry $registry,
        PositionMap $positionMap,
        array &$diagnostics,
    ): void {
        $visitor = new class($registry, $positionMap, $diagnostics) extends NodeVisitorAbstract {
            /** @param list<Diagnostic> $diagnostics */
            public function __construct(
                private readonly Registry $registry,
                private readonly PositionMap $positionMap,
                private array &$diagnostics,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (!$node instanceof Name) {
                    return null;
                }
                $args = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (!is_array($args) || $args === [] || !is_string($fqn)) {
                    return null;
                }
                foreach ($args as $a) {
                    if (!$a->isConcrete()) {
                        return null;
                    }
                }
                try {
                    $this->registry->recordInstantiation($fqn, $args);
                } catch (RuntimeException $e) {
                    // Pin the range to the offending Name's byte span (squiggles
                    // just the generic identifier, e.g. `Box` in
                    // `new Box<int>()`) rather than the whole line. Fall back to
                    // the full line if position info is missing.
                    if ($node->getStartFilePos() >= 0 && $node->getEndFilePos() >= 0) {
                        [$sl, $sc, $el, $ec] = $this->positionMap->rangeFromOffsets(
                            $node->getStartFilePos(),
                            $node->getEndFilePos() + 1,
                        );
                    } else {
                        [$sl, $sc, $el, $ec] = $this->positionMap->fullLineRangeFromNikic($node->getStartLine());
                    }
                    $this->diagnostics[] = new Diagnostic(
                        startLine: $sl,
                        startCharacter: $sc,
                        endLine: $el,
                        endCharacter: $ec,
                        message: $e->getMessage(),
                        // Registry::recordInstantiation has two error paths
                        // (bound violation vs. hash collision). The triage helper
                        // distinguishes them by the message's leading phrase so
                        // editors / users can act on the right hint (raise
                        // XPHP_HASH_LENGTH vs. fix the bound).
                        code: DiagnosticCode::fromRegistryRecordInstantiationException($e),
                    );
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }
}
