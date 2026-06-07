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
use XPHP\Lsp\Resolver\BoundExprView;
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

        // Index every named class declaration so the bound-violation fix-its
        // can (a) offer concrete types that satisfy the bound and (b) locate the
        // offending concrete class to add an `implements` clause. Open files
        // carry source (needed to compute a cross-file edit); filesystem-only
        // hierarchy entries contribute candidate FQNs only.
        $openClasses = [];
        $allClassFqns = [];
        foreach ($files as $path => $entry) {
            foreach (self::collectClasses($entry['ast']) as $cls) {
                $openClasses[$cls['fqn']] = ['uri' => $path, 'node' => $cls['node'], 'source' => $entry['source']];
                $allClassFqns[$cls['fqn']] = true;
            }
        }
        foreach ($hierarchyAsts as $uri => $ast) {
            if (isset($files[$uri])) {
                continue;
            }
            foreach (self::collectClasses($ast) as $cls) {
                $allClassFqns[$cls['fqn']] = true;
            }
        }

        // Second pass: instantiations. Bound violations fire here.
        foreach ($files as $path => $entry) {
            $positionMap = new PositionMap($entry['source']);
            $this->walkInstantiations(
                $entry['ast'],
                $registry,
                $positionMap,
                $entry['source'],
                $hierarchy,
                $openClasses,
                array_keys($allClassFqns),
                $diagnosticsByFile[$path],
            );
        }

        // Third pass: argument-type checking across all call shapes --
        // `new C(…)`, `$obj->m(…)`, `C::m(…)`, `fn(…)`.  Catches the
        // class of bugs where an argument's static type can't satisfy
        // the (substituted) parameter's declared type -- a runtime
        // TypeError waiting to happen.
        $argChecks = (new CallArgumentChecker())->check($files, $hierarchy);
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
     * @param list<Node\Stmt>                                                          $ast
     * @param array<string, array{uri: string, node: ClassLike, source: string}>       $openClasses
     * @param list<string>                                                             $allClassFqns
     * @param list<Diagnostic>                                                         $diagnostics
     */
    private function walkInstantiations(
        array $ast,
        Registry $registry,
        PositionMap $positionMap,
        string $source,
        TypeHierarchy $hierarchy,
        array $openClasses,
        array $allClassFqns,
        array &$diagnostics,
    ): void {
        $analyzer = $this;
        $visitor = new class($registry, $positionMap, $source, $hierarchy, $openClasses, $allClassFqns, $analyzer, $diagnostics) extends NodeVisitorAbstract {
            /**
             * @param array<string, array{uri: string, node: ClassLike, source: string}> $openClasses
             * @param list<string>                                                       $allClassFqns
             * @param list<Diagnostic>                                                   $diagnostics
             */
            public function __construct(
                private readonly Registry $registry,
                private readonly PositionMap $positionMap,
                private readonly string $source,
                private readonly TypeHierarchy $hierarchy,
                private readonly array $openClasses,
                private readonly array $allClassFqns,
                private readonly WorkspaceAnalyzer $analyzer,
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
                    // Registry::recordInstantiation has two error paths
                    // (bound violation vs. hash collision). The triage helper
                    // distinguishes them by the message's leading phrase so
                    // editors / users can act on the right hint (raise
                    // XPHP_HASH_LENGTH vs. fix the bound).
                    $code = DiagnosticCode::fromRegistryRecordInstantiationException($e);
                    $data = $code === DiagnosticCode::BoundViolation
                        ? $this->analyzer->buildBoundFixData(
                            $node,
                            $args,
                            $fqn,
                            $this->source,
                            $this->positionMap,
                            $this->registry,
                            $this->hierarchy,
                            $this->openClasses,
                            $this->allClassFqns,
                        )
                        : null;
                    $this->diagnostics[] = new Diagnostic(
                        startLine: $sl,
                        startCharacter: $sc,
                        endLine: $el,
                        endCharacter: $ec,
                        message: $e->getMessage(),
                        code: $code,
                        data: $data,
                    );
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }

    /**
     * Compute the structured fix-it payload for a generic bound violation:
     * which type parameter / bound was violated, the offending concrete type,
     * the source range of the offending type-argument (for a "swap" fix),
     * concrete workspace types that DO satisfy the bound, and -- when the
     * concrete type is an open-buffer class -- where to add an `implements`
     * clause (for an "implement the interface" fix).
     *
     * Returns null when the violating param can't be pinned down, leaving a
     * plain (fix-less) diagnostic.
     *
     * @param list<mixed>                                                        $args TypeRef[]
     * @param array<string, array{uri: string, node: ClassLike, source: string}> $openClasses
     * @param list<string>                                                       $allClassFqns
     * @return array<string, mixed>|null
     */
    public function buildBoundFixData(
        Name $node,
        array $args,
        string $templateFqn,
        string $source,
        PositionMap $positionMap,
        Registry $registry,
        TypeHierarchy $hierarchy,
        array $openClasses,
        array $allClassFqns,
    ): ?array {
        $definition = $registry->definition($templateFqn);
        if ($definition === null) {
            return null;
        }
        $typeParams = $definition->typeParams;
        if (count($typeParams) !== count($args)) {
            return null;
        }

        // Locate the first type-param whose bound the supplied arg violates.
        $index = null;
        $isSubtype = static fn (string $candidate, string $boundFqn): bool =>
            $hierarchy->isSubtype($candidate, $boundFqn) === true;
        foreach ($typeParams as $i => $param) {
            if ($param->bound === null) {
                continue;
            }
            if (!BoundExprView::isSatisfiedBy($args[$i]->name, $param->bound, $isSubtype)) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return null;
        }

        // The single-leaf candidate / implements fix-its below key off the
        // first leaf FQN; the composite-aware payload arrives later.
        $boundLeaves = BoundExprView::leafFqns($typeParams[$index]->bound);
        $primaryLeaf = $boundLeaves[0] ?? '';
        $concrete = ltrim((string) $args[$index]->name, '\\');
        $concreteIsScalar = (bool) ($args[$index]->isScalar ?? false);

        // Candidate concrete types that satisfy the bound (for the "swap" fix).
        $candidates = [];
        foreach ($allClassFqns as $candidateFqn) {
            if ($candidateFqn === $concrete) {
                continue;
            }
            if (BoundExprView::isSatisfiedBy($candidateFqn, $typeParams[$index]->bound, $isSubtype)) {
                $short = strrpos($candidateFqn, '\\') !== false
                    ? substr($candidateFqn, strrpos($candidateFqn, '\\') + 1)
                    : $candidateFqn;
                $candidates[$short] = true;
            }
        }
        $candidateNames = array_keys($candidates);
        sort($candidateNames);
        $candidateNames = array_slice($candidateNames, 0, 3);

        return [
            'kind' => 'bound',
            'param' => $typeParams[$index]->name,
            'bound' => $primaryLeaf,
            'concrete' => $concrete,
            'concreteIsScalar' => $concreteIsScalar,
            'typeArgRange' => self::typeArgRange($source, $node->getEndFilePos() + 1, $index, $positionMap),
            'candidates' => $candidateNames,
            'implementsInsert' => $concreteIsScalar
                ? null
                : self::implementsInsert($openClasses[$concrete] ?? null, $primaryLeaf),
        ];
    }

    /**
     * Resolve the LSP range of the type-argument at `$index` in the `<…>`
     * clause that follows `$fromOffset` in the original source. Generic
     * clauses are stripped to equal-length whitespace before nikic parses, so
     * byte offsets are 1:1 with the original text the PositionMap was built on.
     *
     * @return array{startLine: int, startCharacter: int, endLine: int, endCharacter: int}|null
     */
    private static function typeArgRange(string $source, int $fromOffset, int $index, PositionMap $positionMap): ?array
    {
        $len = strlen($source);
        $i = $fromOffset;
        while ($i < $len && ctype_space($source[$i])) {
            $i++;
        }
        if ($i >= $len || $source[$i] !== '<') {
            return null;
        }
        $i++;
        $depth = 0;
        $segmentStart = $i;
        $segments = [];
        for (; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '<') {
                $depth++;
            } elseif ($ch === '>') {
                if ($depth === 0) {
                    $segments[] = [$segmentStart, $i];
                    break;
                }
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $segments[] = [$segmentStart, $i];
                $segmentStart = $i + 1;
            }
        }
        if (!isset($segments[$index])) {
            return null;
        }
        [$start, $end] = $segments[$index];
        while ($start < $end && ctype_space($source[$start])) {
            $start++;
        }
        while ($end > $start && ctype_space($source[$end - 1])) {
            $end--;
        }
        if ($end <= $start) {
            return null;
        }
        [$sl, $sc, $el, $ec] = $positionMap->rangeFromOffsets($start, $end);
        return ['startLine' => $sl, 'startCharacter' => $sc, 'endLine' => $el, 'endCharacter' => $ec];
    }

    /**
     * Compute where to insert an `implements \Bound` clause on the concrete
     * class so it satisfies the violated bound. Only open-buffer `class`
     * declarations are supported (the edit needs the file's source). Returns
     * null when the concrete type isn't an editable open class or already
     * implements the bound.
     *
     * @param array{uri: string, node: ClassLike, source: string}|null $entry
     * @return array{uri: string, line: int, character: int, newText: string}|null
     */
    private static function implementsInsert(?array $entry, string $bound): ?array
    {
        if ($entry === null || !$entry['node'] instanceof Node\Stmt\Class_) {
            return null;
        }
        $class = $entry['node'];
        $boundShort = strrpos($bound, '\\') !== false ? substr($bound, strrpos($bound, '\\') + 1) : $bound;
        foreach ($class->implements as $impl) {
            $parts = $impl->getParts();
            if (end($parts) === $boundShort) {
                return null; // already implements it
            }
        }

        if ($class->implements !== []) {
            $anchor = $class->implements[count($class->implements) - 1];
            $newText = ', \\' . $bound;
        } elseif ($class->extends !== null) {
            $anchor = $class->extends;
            $newText = ' implements \\' . $bound;
        } elseif ($class->name !== null) {
            $anchor = $class->name;
            $newText = ' implements \\' . $bound;
        } else {
            return null;
        }
        $insertOffset = $anchor->getEndFilePos() + 1;
        if ($insertOffset <= 0) {
            return null;
        }
        [$line, $character] = (new PositionMap($entry['source']))->offsetToPosition($insertOffset);
        return ['uri' => $entry['uri'], 'line' => $line, 'character' => $character, 'newText' => $newText];
    }

    /**
     * Every named-class FQN declared in an AST. Used by the diagnostics
     * provider to keep the bound-check hierarchy free of duplicate-FQN
     * collisions: a filesystem file enters the hierarchy only when it's the
     * nearest declarer of a class it defines.
     *
     * @param list<Node\Stmt> $ast
     * @return list<string>
     */
    public static function classFqnsIn(array $ast): array
    {
        return array_map(static fn (array $cls): string => $cls['fqn'], self::collectClasses($ast));
    }

    /**
     * Collect every named ClassLike in an AST paired with its computed FQN
     * (namespace + short name). Mirrors collectTemplateDeclarations but for
     * ALL classes, not just generic templates.
     *
     * @param list<Node\Stmt> $ast
     * @return list<array{fqn: string, node: ClassLike}>
     */
    private static function collectClasses(array $ast): array
    {
        $out = [];
        foreach ($ast as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $ns = $stmt->name === null ? '' : $stmt->name->toString();
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof ClassLike && $inner->name !== null) {
                        $short = $inner->name->toString();
                        $out[] = ['fqn' => $ns !== '' ? $ns . '\\' . $short : $short, 'node' => $inner];
                    }
                }
                continue;
            }
            if ($stmt instanceof ClassLike && $stmt->name !== null) {
                $out[] = ['fqn' => $stmt->name->toString(), 'node' => $stmt];
            }
        }
        return $out;
    }
}
