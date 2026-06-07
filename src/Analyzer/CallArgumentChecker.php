<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\TypeHierarchy;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Post-monomorphization argument-type checker.  Walks every call site
 * across the workspace and emits a diagnostic when a supplied argument's
 * statically-known type can't satisfy the callee parameter's declared
 * type (after generic type-arg substitution).  Four call shapes:
 *
 *   - `new C(…)` / `new C<T>(…)`     → `xphp.ctor-arg-mismatch`   (V1)
 *   - `$obj->m(…)`                   → `xphp.arg-mismatch`        (V2)
 *   - `C::m(…)` / `C::m<T>(…)`       → `xphp.arg-mismatch`        (V2)
 *   - `fn(…)`   / `fn<T>(…)`         → `xphp.arg-mismatch`        (V2)
 *
 * Argument type inference is intentionally narrow -- only the cases
 * where the AST alone tells us the type:
 *   - `new ClassName(...)` → ClassName FQN
 *   - string / int / float literals → the obvious scalar
 *   - `true` / `false` / `null` const fetch → bool / null
 *   - array literal `[…]` → array
 *   - a `$var` assigned EXACTLY ONCE, earlier in the same function
 *     scope, from one of the above ("simple locals").
 *
 * Anything else (function-call results, properties, ternaries,
 * reassigned or forward-assigned variables) is SKIPPED to avoid false
 * positives.  The same simple-locals binding map is what lets an
 * instance method call's receiver type be inferred
 * (`$users = new Collection<User>(); $users->add(42);`).
 *
 * Comparison rules: see {@see isSatisfied()}.  Class ancestry defers to
 * the workspace `TypeHierarchy`; unknown ancestry is treated as OK so we
 * never false-positive on types missing from the index.
 */
final readonly class CallArgumentChecker
{
    /**
     * Sentinel returned by renderType for a param typed by a type-param the
     * call site left unresolved (omitted arg with no default). It contains a
     * NUL byte so it can never collide with a real type name.
     */
    private const UNRESOLVED_TYPE_PARAM = "\0unresolved-type-param";

    /** Scalar param types the checker can compare against literals. */
    private const SCALARS = ['string' => true, 'int' => true, 'float' => true, 'bool' => true, 'array' => true];

    /** Pseudo / supertype params that accept anything. */
    private const PERMISSIVE_TYPES = [
        'mixed' => true,
        'object' => true,
        'callable' => true,
        'iterable' => true,
        'void' => true,
        'never' => true,
        'self' => true,
        'static' => true,
        'parent' => true,
    ];

    /**
     * @param array<string, array{ast: list<Node\Stmt>, source: string}>  $files
     * @return array<string, list<Diagnostic>> diagnostics keyed by URI/path
     */
    public function check(array $files, TypeHierarchy $hierarchy): array
    {
        $classIndex = $this->indexClassesByFqn($files);
        $functionIndex = $this->indexFunctionsByFqn($files);
        $diagnosticsByFile = array_fill_keys(array_keys($files), []);

        foreach ($files as $path => $entry) {
            $positionMap = new PositionMap($entry['source']);
            $context = self::extractNamespaceAndUseMap($entry['ast']);
            $bindings = $this->collectScopeBindings(
                $entry['ast'],
                $context['namespace'],
                $context['useMap'],
            );
            $this->walkCalls(
                $entry['ast'],
                $classIndex,
                $functionIndex,
                $bindings,
                $hierarchy,
                $positionMap,
                $context['namespace'],
                $context['useMap'],
                $diagnosticsByFile[$path],
            );
        }
        return $diagnosticsByFile;
    }

    /**
     * Extract the file's enclosing namespace + the `use Foo\Bar [as
     * Baz]` map needed to resolve bare `Name` nodes to fully-qualified
     * class names without relying on nikic's NameResolver (which the
     * LSP's per-file Analyzer doesn't run).
     *
     * Handles both `Use_` and `GroupUse` (only the TYPE_NORMAL slots
     * -- function / const uses go through separate symbol tables and
     * don't bind class-like aliases).
     *
     * @param list<Node\Stmt> $ast
     * @return array{namespace: string, useMap: array<string, string>}
     */
    private static function extractNamespaceAndUseMap(array $ast): array
    {
        $namespace = '';
        $useMap = [];
        $topLevelStmts = $ast;
        foreach ($ast as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $namespace = $stmt->name === null ? '' : $stmt->name->toString();
                $topLevelStmts = $stmt->stmts;
                break;
            }
        }
        foreach ($topLevelStmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Use_) {
                foreach ($stmt->uses as $useUse) {
                    $type = $useUse->type !== Node\Stmt\Use_::TYPE_UNKNOWN
                        ? $useUse->type
                        : $stmt->type;
                    if ($type !== Node\Stmt\Use_::TYPE_NORMAL) {
                        continue;
                    }
                    $useMap[$useUse->getAlias()->toString()] = $useUse->name->toString();
                }
                continue;
            }
            if ($stmt instanceof Node\Stmt\GroupUse) {
                $prefix = $stmt->prefix->toString();
                foreach ($stmt->uses as $useUse) {
                    $type = $useUse->type !== Node\Stmt\Use_::TYPE_UNKNOWN
                        ? $useUse->type
                        : $stmt->type;
                    if ($type !== Node\Stmt\Use_::TYPE_NORMAL) {
                        continue;
                    }
                    $useMap[$useUse->getAlias()->toString()] = $prefix . '\\' . $useUse->name->toString();
                }
            }
        }
        return ['namespace' => $namespace, 'useMap' => $useMap];
    }

    /**
     * Resolve a `Name` node to an FQN given the file's namespace and
     * use map.  Handles the three nikic-classified shapes:
     *
     *   - fully-qualified `\App\Foo` → strip leading slash;
     *   - relative `namespace\Foo` → prepend file namespace;
     *   - unqualified / qualified `Foo` / `Foo\Bar` → consult use map
     *     for the head segment, otherwise prepend file namespace.
     *
     * @param array<string, string> $useMap
     */
    public function resolveNameToFqn(Name $name, string $namespace, array $useMap): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }
        $parts = $name->getParts();
        if ($parts === []) {
            return '';
        }
        $head = $parts[0];
        if (isset($useMap[$head])) {
            $tail = array_slice($parts, 1);
            return $tail === []
                ? $useMap[$head]
                : $useMap[$head] . '\\' . implode('\\', $tail);
        }
        $local = implode('\\', $parts);
        return $namespace !== '' ? $namespace . '\\' . $local : $local;
    }

    /**
     * Index every named ClassLike in the workspace by FQN, carrying its
     * declared methods (keyed by lowercased name), the owning ClassLike
     * (so callers can read its ATTR_GENERIC_PARAMS) and the declaring
     * file's import context (params resolve in the OWNER's namespace).
     *
     * @param array<string, array{ast: list<Node\Stmt>, source: string}> $files
     * @return array<string, array{owner: ClassLike, methods: array<string, ClassMethod>, namespace: string, useMap: array<string, string>}>
     */
    private function indexClassesByFqn(array $files): array
    {
        $byFqn = [];
        foreach ($files as $entry) {
            $context = self::extractNamespaceAndUseMap($entry['ast']);
            foreach (self::collectClassLikesWithNamespace($entry['ast']) as [$namespace, $cls]) {
                if ($cls->name === null) {
                    continue;
                }
                $shortName = $cls->name->toString();
                $fqn = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
                $methods = [];
                foreach ($cls->stmts as $member) {
                    if ($member instanceof ClassMethod) {
                        $methods[strtolower($member->name->toString())] = $member;
                    }
                }
                $byFqn[$fqn] = [
                    'owner' => $cls,
                    'methods' => $methods,
                    'namespace' => $namespace,
                    'useMap' => $context['useMap'],
                ];
            }
        }
        return $byFqn;
    }

    /**
     * Index every named free function in the workspace by FQN.
     *
     * @param array<string, array{ast: list<Node\Stmt>, source: string}> $files
     * @return array<string, array{func: Function_, namespace: string, useMap: array<string, string>}>
     */
    private function indexFunctionsByFqn(array $files): array
    {
        $byFqn = [];
        foreach ($files as $entry) {
            $context = self::extractNamespaceAndUseMap($entry['ast']);
            foreach (self::collectFunctionsWithNamespace($entry['ast']) as [$namespace, $fn]) {
                $shortName = $fn->name->toString();
                $fqn = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
                $byFqn[$fqn] = [
                    'func' => $fn,
                    'namespace' => $namespace,
                    'useMap' => $context['useMap'],
                ];
            }
        }
        return $byFqn;
    }

    /**
     * Recursively walk the top-level statement list collecting every
     * `ClassLike` paired with its enclosing namespace string (empty
     * when the file has no `namespace` declaration).  Handles both
     * the "bracketed" form (`namespace App { ... }`) and the
     * "semicolon" form (`namespace App; ...`).
     *
     * @param list<Node\Stmt> $stmts
     * @return list<array{0: string, 1: ClassLike}>
     */
    private static function collectClassLikesWithNamespace(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $ns = $stmt->name === null ? '' : $stmt->name->toString();
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof ClassLike) {
                        $out[] = [$ns, $inner];
                    }
                }
                continue;
            }
            if ($stmt instanceof ClassLike) {
                $out[] = ['', $stmt];
            }
        }
        return $out;
    }

    /**
     * Companion of {@see collectClassLikesWithNamespace()} for free
     * functions.
     *
     * @param list<Node\Stmt> $stmts
     * @return list<array{0: string, 1: Function_}>
     */
    private static function collectFunctionsWithNamespace(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $ns = $stmt->name === null ? '' : $stmt->name->toString();
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof Function_) {
                        $out[] = [$ns, $inner];
                    }
                }
                continue;
            }
            if ($stmt instanceof Function_) {
                $out[] = ['', $stmt];
            }
        }
        return $out;
    }

    /**
     * Build the per-scope "simple locals" binding map: for each function
     * scope (keyed by the FunctionLike's object id; 0 = file top level)
     * a `$var -> binding` map of variables assigned EXACTLY ONCE from an
     * inferable literal / `new` expression.  Variables assigned more than
     * once in a scope are dropped (ambiguous).  Each binding records the
     * assignment's byte offset so lookups can require assign-before-use.
     *
     * @param array<string, string> $useMap
     * @return array<int, array<string, array{type: ?string, fqn: ?string, args: ?list<TypeRef>, offset: int}>>
     */
    private function collectScopeBindings(array $ast, string $namespace, array $useMap): array
    {
        $checker = $this;
        $visitor = new class($checker, $namespace, $useMap) extends NodeVisitorAbstract {
            /** @var list<int> stack of enclosing FunctionLike object ids */
            private array $scopeStack = [];

            /** @var array<int, array<string, array{type: ?string, fqn: ?string, args: ?list<TypeRef>, offset: int}>> */
            public array $bindings = [0 => []];

            /** @var array<int, array<string, true>> scope -> vars seen more than once */
            private array $ambiguous = [0 => []];

            /** @param array<string, string> $useMap */
            public function __construct(
                private readonly CallArgumentChecker $checker,
                private readonly string $namespace,
                private readonly array $useMap,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof FunctionLike) {
                    $id = spl_object_id($node);
                    $this->scopeStack[] = $id;
                    $this->bindings[$id] ??= [];
                    $this->ambiguous[$id] ??= [];
                    return null;
                }
                if (!$node instanceof Assign || !$node->var instanceof Variable || !is_string($node->var->name)) {
                    return null;
                }
                $scope = $this->scopeStack === [] ? 0 : $this->scopeStack[count($this->scopeStack) - 1];
                $name = $node->var->name;
                if (isset($this->ambiguous[$scope][$name])) {
                    return null;
                }
                if (isset($this->bindings[$scope][$name])) {
                    // Second assignment in the same scope -> ambiguous; drop it.
                    unset($this->bindings[$scope][$name]);
                    $this->ambiguous[$scope][$name] = true;
                    return null;
                }
                $binding = $this->checker->bindingForExpr($node->expr, $this->namespace, $this->useMap);
                if ($binding === null) {
                    return null;
                }
                $offset = $node->getStartFilePos();
                $this->bindings[$scope][$name] = $binding + ['offset' => $offset >= 0 ? $offset : 0];
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof FunctionLike && $this->scopeStack !== []) {
                    array_pop($this->scopeStack);
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->bindings;
    }

    /**
     * Compute the binding for an assignment RHS, or null when its type
     * isn't statically inferable.  Mirrors {@see inferArgType()} but also
     * captures the class FQN + generic args needed to type a receiver.
     *
     * @param array<string, string> $useMap
     * @return array{type: ?string, fqn: ?string, args: ?list<TypeRef>}|null
     */
    public function bindingForExpr(Expr $expr, string $namespace, array $useMap): ?array
    {
        if ($expr instanceof New_ && $expr->class instanceof Name) {
            $fqn = $this->resolveTargetClassFqn($expr->class, $namespace, $useMap);
            $args = $expr->class->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
            return [
                'type' => $fqn !== '' ? $fqn : null,
                'fqn' => $fqn !== '' ? $fqn : null,
                'args' => is_array($args) ? $args : null,
            ];
        }
        $scalar = $this->inferArgType($expr, $namespace, $useMap, []);
        if ($scalar === null) {
            return null;
        }
        return ['type' => $scalar, 'fqn' => null, 'args' => null];
    }

    /**
     * Resolve the target class FQN of a `new C(…)` expression.
     * Prefers the xphp-parser-attached `ATTR_TEMPLATE_FQN` (set for
     * generic `new C<T>(…)` shapes), then resolves bare names via the
     * call site's namespace + use map.  Returns the empty string when
     * nothing can be resolved.
     *
     * @param array<string, string> $useMap
     */
    public function resolveTargetClassFqn(Name $classExpr, string $namespace, array $useMap): string
    {
        $generic = $classExpr->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
        if (is_string($generic) && $generic !== '') {
            return ltrim($generic, '\\');
        }
        return $this->resolveNameToFqn($classExpr, $namespace, $useMap);
    }

    /**
     * Pair a template's declared TypeParams with a list of call-site
     * TypeRefs into a `name -> TypeRef` substitution map.  Returns an
     * empty map when the shapes don't line up (nothing to substitute).
     *
     * @param array<int, mixed>|null $params declared type params (TypeParam[])
     * @param array<int, mixed>|null $args   supplied type args (TypeRef[])
     * @return array<string, TypeRef>
     */
    private function pairSubstitution(?array $params, ?array $args): array
    {
        if (!is_array($params) || !is_array($args) || $params === []) {
            return [];
        }
        // 0.2.x lets a call site OMIT trailing args that have a declared
        // default. Supplying more args than params is still wrong (don't pair);
        // supplying the same number or fewer is fine -- pad the missing trailing
        // slots from each param's default, resolving left-to-right so a default
        // that references an earlier param (`Pair<A, B = A>`) picks up the
        // already-substituted arg.
        if (count($args) > count($params)) {
            return [];
        }
        $args = $this->padArgsWithDefaults($params, $args);

        $names = self::extractTypeParamNames($params);
        $substitution = [];
        foreach ($names as $i => $paramName) {
            $arg = $args[$i] ?? null;
            // A still-missing slot (no supplied arg and no default) is recorded
            // as an UNRESOLVED type-param sentinel -- never a false "too few
            // type args", and a method param typed by it is skipped rather than
            // resolved to a bogus `App\<ParamName>` class.
            $substitution[$paramName] = $arg instanceof TypeRef
                ? $arg
                : new TypeRef($paramName, [], false, true);
        }
        return $substitution;
    }

    /**
     * Pad `$args` with the trailing `$params`' defaults, substituting earlier
     * positional args into any type-param reference in a default (so
     * `Pair<A, B = A>` called as `::<int>` pads `B` to `int`). Slots with no
     * supplied arg and no default are left absent. Mirrors the vendor's
     * `Registry::padArgsWithDefaults` pad semantics.
     *
     * @param array<int, mixed> $params
     * @param array<int, TypeRef> $args
     * @return array<int, TypeRef>
     */
    private function padArgsWithDefaults(array $params, array $args): array
    {
        $defaults = self::extractTypeParamDefaults($params);
        $names = self::extractTypeParamNames($params);
        $padded = $args;
        for ($i = count($args); $i < count($params); $i++) {
            $default = $defaults[$i] ?? null;
            if (!$default instanceof TypeRef) {
                // No default -> stop padding; the remaining slots stay absent.
                break;
            }
            // Resolve type-param references in the default against the args
            // already positioned (including ones we just padded).
            $subst = [];
            foreach ($padded as $j => $concrete) {
                if (isset($names[$j]) && $concrete instanceof TypeRef) {
                    $subst[$names[$j]] = $concrete;
                }
            }
            $padded[$i] = self::resolveDefault($default, $subst);
        }
        return $padded;
    }

    /**
     * Substitute type-param references in a default `TypeRef` with the bound
     * concrete args. A bare `T` default becomes the arg bound to `T`; nested
     * args (`List<T>`) are resolved recursively. Unknown references pass through
     * unchanged.
     *
     * @param array<string, TypeRef> $subst
     */
    private static function resolveDefault(TypeRef $default, array $subst): TypeRef
    {
        if ($default->isTypeParam && isset($subst[$default->name])) {
            return $subst[$default->name];
        }
        if ($default->args === []) {
            return $default;
        }
        $newArgs = array_map(
            static fn (TypeRef $arg): TypeRef => self::resolveDefault($arg, $subst),
            $default->args,
        );
        return new TypeRef($default->name, $newArgs, $default->isScalar, $default->isTypeParam);
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, ?TypeRef>
     */
    private static function extractTypeParamDefaults(array $params): array
    {
        $defaults = [];
        foreach (array_values($params) as $i => $p) {
            $defaults[$i] = (is_object($p) && property_exists($p, 'default') && $p->default instanceof TypeRef)
                ? $p->default
                : null;
        }
        return $defaults;
    }

    /**
     * @param array<int, mixed> $params
     * @return list<string>
     */
    private static function extractTypeParamNames(array $params): array
    {
        $names = [];
        foreach ($params as $p) {
            if (is_object($p) && property_exists($p, 'name') && is_string($p->name)) {
                $names[] = $p->name;
            }
        }
        return $names;
    }

    /**
     * Single traversal that checks every call site.  `New_` keeps the V1
     * `xphp.ctor-arg-mismatch` surface; the three V2 shapes emit
     * `xphp.arg-mismatch`.  Method-call receivers are typed via the
     * scope binding map (so `$x->m(…)` only checks when `$x`'s class is
     * locally known).
     *
     * @param array<string, array{owner: ClassLike, methods: array<string, ClassMethod>, namespace: string, useMap: array<string, string>}> $classIndex
     * @param array<string, array{func: Function_, namespace: string, useMap: array<string, string>}>                                        $functionIndex
     * @param array<int, array<string, array{type: ?string, fqn: ?string, args: ?list<TypeRef>, offset: int}>>                               $bindings
     * @param array<string, string>                                                                                                          $useMap
     * @param list<Diagnostic>                                                                                                               $diagnostics
     */
    private function walkCalls(
        array $ast,
        array $classIndex,
        array $functionIndex,
        array $bindings,
        TypeHierarchy $hierarchy,
        PositionMap $positionMap,
        string $namespace,
        array $useMap,
        array &$diagnostics,
    ): void {
        $checker = $this;
        $visitor = new class($checker, $classIndex, $functionIndex, $bindings, $hierarchy, $positionMap, $namespace, $useMap, $diagnostics) extends NodeVisitorAbstract {
            /** @var list<int> */
            private array $scopeStack = [];

            /**
             * @param array<string, array{owner: ClassLike, methods: array<string, ClassMethod>, namespace: string, useMap: array<string, string>}> $classIndex
             * @param array<string, array{func: Function_, namespace: string, useMap: array<string, string>}>                                        $functionIndex
             * @param array<int, array<string, array{type: ?string, fqn: ?string, args: ?list<TypeRef>, offset: int}>>                               $bindings
             * @param array<string, string>                                                                                                          $useMap
             * @param list<Diagnostic>                                                                                                               $diagnostics
             */
            public function __construct(
                private readonly CallArgumentChecker $checker,
                private readonly array $classIndex,
                private readonly array $functionIndex,
                private readonly array $bindings,
                private readonly TypeHierarchy $hierarchy,
                private readonly PositionMap $positionMap,
                private readonly string $namespace,
                private readonly array $useMap,
                public array &$diagnostics,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof FunctionLike) {
                    $this->scopeStack[] = spl_object_id($node);
                    return null;
                }
                $scope = $this->scopeStack === [] ? 0 : $this->scopeStack[count($this->scopeStack) - 1];
                $scopeBindings = $this->bindings[$scope] ?? [];

                if ($node instanceof New_) {
                    $this->checker->checkNew($node, $this->classIndex, $scopeBindings, $this->hierarchy, $this->positionMap, $this->namespace, $this->useMap, $this->diagnostics);
                } elseif ($node instanceof MethodCall) {
                    $this->checker->checkMethodCall($node, $this->classIndex, $scopeBindings, $this->hierarchy, $this->positionMap, $this->namespace, $this->useMap, $this->diagnostics);
                } elseif ($node instanceof StaticCall) {
                    $this->checker->checkStaticCall($node, $this->classIndex, $scopeBindings, $this->hierarchy, $this->positionMap, $this->namespace, $this->useMap, $this->diagnostics);
                } elseif ($node instanceof FuncCall) {
                    $this->checker->checkFuncCall($node, $this->functionIndex, $scopeBindings, $this->hierarchy, $this->positionMap, $this->namespace, $this->useMap, $this->diagnostics);
                }
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof FunctionLike && $this->scopeStack !== []) {
                    array_pop($this->scopeStack);
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }

    /**
     * `new C(…)` / `new C<T>(…)`.
     *
     * @param array<string, array{owner: ClassLike, methods: array<string, ClassMethod>, namespace: string, useMap: array<string, string>}> $classIndex
     * @param array<string, array{type: ?string, fqn: ?string, args: ?list<TypeRef>, offset: int}>                                          $scopeBindings
     * @param list<Diagnostic>                                                                                                               $diagnostics
     */
    public function checkNew(
        New_ $node,
        array $classIndex,
        array $scopeBindings,
        TypeHierarchy $hierarchy,
        PositionMap $positionMap,
        string $namespace,
        array $useMap,
        array &$diagnostics,
    ): void {
        if (!$node->class instanceof Name) {
            return;
        }
        $fqn = $this->resolveTargetClassFqn($node->class, $namespace, $useMap);
        if ($fqn === '' || !isset($classIndex[$fqn]['methods']['__construct'])) {
            return;
        }
        $entry = $classIndex[$fqn];
        $substitution = $this->pairSubstitution(
            $entry['owner']->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS),
            $node->class->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS),
        );
        $this->checkArguments(
            $node->args,
            $entry['methods']['__construct']->params,
            $substitution,
            $entry['namespace'],
            $entry['useMap'],
            $namespace,
            $useMap,
            $scopeBindings,
            $hierarchy,
            $positionMap,
            'Constructor argument',
            $fqn,
            DiagnosticCode::ConstructorArgumentMismatch,
            $diagnostics,
        );
    }

    /**
     * `$obj->m(…)` -- receiver typed via the scope binding map.
     *
     * @param array<string, array{owner: ClassLike, methods: array<string, ClassMethod>, namespace: string, useMap: array<string, string>}> $classIndex
     * @param array<string, array{type: ?string, fqn: ?string, args: ?list<TypeRef>, offset: int}>                                          $scopeBindings
     * @param list<Diagnostic>                                                                                                               $diagnostics
     */
    public function checkMethodCall(
        MethodCall $node,
        array $classIndex,
        array $scopeBindings,
        TypeHierarchy $hierarchy,
        PositionMap $positionMap,
        string $namespace,
        array $useMap,
        array &$diagnostics,
    ): void {
        if (!$node->name instanceof Identifier) {
            return;
        }
        $receiver = $this->receiverBinding($node->var, $node, $scopeBindings);
        if ($receiver === null || $receiver['fqn'] === null) {
            return;
        }
        $entry = $classIndex[$receiver['fqn']] ?? null;
        if ($entry === null) {
            return;
        }
        $method = $entry['methods'][strtolower($node->name->toString())] ?? null;
        if ($method === null) {
            return;
        }
        // Class-level generics from the receiver's `new C<...>()`, plus any
        // method-level generics on the call (`$x->m<T>(…)`).
        $substitution = $this->pairSubstitution(
            $entry['owner']->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS),
            $receiver['args'],
        );
        $substitution += $this->pairSubstitution(
            $method->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS),
            $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS),
        );
        $this->checkArguments(
            $node->args,
            $method->params,
            $substitution,
            $entry['namespace'],
            $entry['useMap'],
            $namespace,
            $useMap,
            $scopeBindings,
            $hierarchy,
            $positionMap,
            'Argument',
            $receiver['fqn'] . '::' . $node->name->toString() . '()',
            DiagnosticCode::ArgumentMismatch,
            $diagnostics,
        );
    }

    /**
     * `C::m(…)` / `C::m<T>(…)`.  Class-level generics can't be bound in a
     * static context, so only method-level generics are substituted;
     * unsubstituted class type-params stay as bare names and never
     * false-positive (unknown class ancestry is treated as OK).
     *
     * @param array<string, array{owner: ClassLike, methods: array<string, ClassMethod>, namespace: string, useMap: array<string, string>}> $classIndex
     * @param array<string, array{type: ?string, fqn: ?string, args: ?list<TypeRef>, offset: int}>                                          $scopeBindings
     * @param list<Diagnostic>                                                                                                               $diagnostics
     */
    public function checkStaticCall(
        StaticCall $node,
        array $classIndex,
        array $scopeBindings,
        TypeHierarchy $hierarchy,
        PositionMap $positionMap,
        string $namespace,
        array $useMap,
        array &$diagnostics,
    ): void {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
            return;
        }
        $fqn = $this->resolveTargetClassFqn($node->class, $namespace, $useMap);
        $entry = $classIndex[$fqn] ?? null;
        if ($entry === null) {
            return;
        }
        $method = $entry['methods'][strtolower($node->name->toString())] ?? null;
        if ($method === null) {
            return;
        }
        $substitution = $this->pairSubstitution(
            $method->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS),
            $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS),
        );
        $this->checkArguments(
            $node->args,
            $method->params,
            $substitution,
            $entry['namespace'],
            $entry['useMap'],
            $namespace,
            $useMap,
            $scopeBindings,
            $hierarchy,
            $positionMap,
            'Argument',
            $fqn . '::' . $node->name->toString() . '()',
            DiagnosticCode::ArgumentMismatch,
            $diagnostics,
        );
    }

    /**
     * `fn(…)` / `fn<T>(…)`.
     *
     * @param array<string, array{func: Function_, namespace: string, useMap: array<string, string>}> $functionIndex
     * @param array<string, array{type: ?string, fqn: ?string, args: ?list<TypeRef>, offset: int}>    $scopeBindings
     * @param list<Diagnostic>                                                                         $diagnostics
     */
    public function checkFuncCall(
        FuncCall $node,
        array $functionIndex,
        array $scopeBindings,
        TypeHierarchy $hierarchy,
        PositionMap $positionMap,
        string $namespace,
        array $useMap,
        array &$diagnostics,
    ): void {
        if (!$node->name instanceof Name) {
            return;
        }
        $fqn = $this->resolveNameToFqn($node->name, $namespace, $useMap);
        $entry = $functionIndex[$fqn] ?? null;
        if ($entry === null) {
            return;
        }
        $substitution = $this->pairSubstitution(
            $entry['func']->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS),
            $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS),
        );
        $this->checkArguments(
            $node->args,
            $entry['func']->params,
            $substitution,
            $entry['namespace'],
            $entry['useMap'],
            $namespace,
            $useMap,
            $scopeBindings,
            $hierarchy,
            $positionMap,
            'Argument',
            $fqn . '()',
            DiagnosticCode::ArgumentMismatch,
            $diagnostics,
        );
    }

    /**
     * Resolve a call's receiver to its scope binding.  Only plain
     * `$var` receivers assigned before the call are typed (conservative);
     * `$this`, chained calls, and properties are skipped.
     *
     * @param array<string, array{type: ?string, fqn: ?string, args: ?list<TypeRef>, offset: int}> $scopeBindings
     * @return array{type: ?string, fqn: ?string, args: ?list<TypeRef>, offset: int}|null
     */
    private function receiverBinding(Expr $receiver, Node $call, array $scopeBindings): ?array
    {
        if (!$receiver instanceof Variable || !is_string($receiver->name)) {
            return null;
        }
        $binding = $scopeBindings[$receiver->name] ?? null;
        if ($binding === null) {
            return null;
        }
        $callOffset = $call->getStartFilePos();
        if ($callOffset >= 0 && $binding['offset'] > $callOffset) {
            // Assigned after this call -- don't trust it.
            return null;
        }
        return $binding;
    }

    /**
     * Compare each positional argument against its (substituted) callee
     * parameter type, emitting one Diagnostic per mismatch.
     *
     * @param array<int, Node\Arg|Node\VariadicPlaceholder>                                          $argNodes
     * @param list<Param>                                                                            $params
     * @param array<string, TypeRef>                                                                 $substitution
     * @param array<string, string>                                                                  $ownerUseMap
     * @param array<string, string>                                                                  $callerUseMap
     * @param array<string, array{type: ?string, fqn: ?string, args: ?list<TypeRef>, offset: int}>  $scopeBindings
     * @param list<Diagnostic>                                                                       $diagnostics
     */
    private function checkArguments(
        array $argNodes,
        array $params,
        array $substitution,
        string $ownerNamespace,
        array $ownerUseMap,
        string $callerNamespace,
        array $callerUseMap,
        array $scopeBindings,
        TypeHierarchy $hierarchy,
        PositionMap $positionMap,
        string $noun,
        string $calleeLabel,
        DiagnosticCode $code,
        array &$diagnostics,
    ): void {
        foreach ($argNodes as $i => $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }
            $param = self::paramAtIndex($params, $i);
            if ($param === null) {
                continue;
            }
            $expectedType = $this->extractParamType($param, $substitution, $ownerNamespace, $ownerUseMap);
            if ($expectedType === null) {
                continue;
            }
            $actualType = $this->inferArgType($arg->value, $callerNamespace, $callerUseMap, $scopeBindings);
            if ($actualType === null) {
                continue;
            }
            if (self::isSatisfied($actualType, $expectedType, $hierarchy)) {
                continue;
            }
            $diagnostics[] = self::buildMismatchDiagnostic(
                $arg->value,
                $positionMap,
                $i + 1,
                $param,
                $expectedType,
                $actualType,
                $noun,
                $calleeLabel,
                $code,
            );
        }
    }

    /**
     * Resolve the param record for a given positional argument index,
     * honoring variadics (last param consumes all trailing args).
     *
     * @param list<Param> $params
     */
    private static function paramAtIndex(array $params, int $index): ?Param
    {
        if (isset($params[$index])) {
            return $params[$index];
        }
        $last = $params[count($params) - 1] ?? null;
        if ($last !== null && $last->variadic) {
            return $last;
        }
        return null;
    }

    /**
     * Extract the param's declared type as a normalized display string,
     * applying type-arg substitution when the param type references a
     * generic type parameter.  Returns null when the param has no
     * type hint.
     *
     * For union / intersection types, returns the rendered form
     * (`A|B` / `A&B`).  Nullable types are rendered with the leading
     * `?`.
     *
     * The `$namespace` + `$useMap` are the OWNER's (the declaring
     * class's), not the call site's -- non-generic class-type params
     * resolve in the declaring file's import context.
     *
     * @param array<string, TypeRef> $substitution
     * @param array<string, string>  $useMap
     */
    private function extractParamType(Param $param, array $substitution, string $namespace, array $useMap): ?string
    {
        $type = $param->type;
        if ($type === null) {
            return null;
        }
        $rendered = $this->renderType($type, $substitution, $namespace, $useMap);
        // A param typed by a type-param that the call site left unresolved
        // (omitted arg, no default) can't be checked -- treat as untyped.
        if (str_contains($rendered, self::UNRESOLVED_TYPE_PARAM)) {
            return null;
        }
        return $rendered;
    }

    /**
     * @param array<string, TypeRef> $substitution
     * @param array<string, string>  $useMap
     */
    private function renderType(Node $type, array $substitution, string $namespace, array $useMap): string
    {
        if ($type instanceof NullableType) {
            return '?' . $this->renderType($type->type, $substitution, $namespace, $useMap);
        }
        if ($type instanceof Node\UnionType) {
            $parts = array_map(fn (Node $t): string => $this->renderType($t, $substitution, $namespace, $useMap), $type->types);
            return implode('|', $parts);
        }
        if ($type instanceof Node\IntersectionType) {
            $parts = array_map(fn (Node $t): string => $this->renderType($t, $substitution, $namespace, $useMap), $type->types);
            return implode('&', $parts);
        }
        if ($type instanceof Identifier) {
            return $type->toString();
        }
        if ($type instanceof Name) {
            $raw = ltrim($type->toString(), '\\');
            // Generic type-param substitution: param `T $x` resolves
            // to whatever the instantiation passed for T.
            if (isset($substitution[$raw])) {
                // An unresolved type-param (omitted arg, no default) stays a
                // type-param: skip the check rather than resolve `T` to a bogus
                // `App\T` class.
                if ($substitution[$raw]->isTypeParam) {
                    return self::UNRESOLVED_TYPE_PARAM;
                }
                return ltrim($substitution[$raw]->name, '\\');
            }
            // Bare scalar / reserved type names (`string`, `int`,
            // `self`, etc.) stay as-is -- no FQN resolution.
            if ($type->isUnqualified() && self::isReservedTypeName($raw)) {
                return $raw;
            }
            return $this->resolveNameToFqn($type, $namespace, $useMap);
        }
        return '';
    }

    /**
     * Recognises PHP's reserved scalar / pseudo type names that
     * shouldn't be FQN-resolved against the use map.
     */
    private static function isReservedTypeName(string $name): bool
    {
        $lower = strtolower($name);
        return isset(self::SCALARS[$lower])
            || isset(self::PERMISSIVE_TYPES[$lower])
            || $lower === 'null'
            || $lower === 'true'
            || $lower === 'false';
    }

    /**
     * AST-only argument type inference, extended with the scope binding
     * map for plain `$var`s.  Returns null when the static type isn't
     * visible from the expression (or binding) alone.
     *
     * @param array<string, string>                                                                  $useMap
     * @param array<string, array{type: ?string, fqn: ?string, args: ?list<TypeRef>, offset: int}>  $scopeBindings
     */
    private function inferArgType(Expr $expr, string $namespace, array $useMap, array $scopeBindings): ?string
    {
        if ($expr instanceof New_) {
            if (!$expr->class instanceof Name) {
                return null;
            }
            // Generic instantiation: ATTR_TEMPLATE_FQN gives the
            // template FQN; for the satisfaction check we compare
            // against the template name, not the mangled
            // specialization name -- that lines up with the param's
            // pre-substitution type.
            $templateFqn = $expr->class->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
            if (is_string($templateFqn) && $templateFqn !== '') {
                return ltrim($templateFqn, '\\');
            }
            return $this->resolveNameToFqn($expr->class, $namespace, $useMap);
        }
        if ($expr instanceof String_) {
            return 'string';
        }
        if ($expr instanceof Int_) {
            return 'int';
        }
        if ($expr instanceof Float_) {
            return 'float';
        }
        if ($expr instanceof Array_) {
            return 'array';
        }
        if ($expr instanceof ConstFetch) {
            $name = strtolower($expr->name->toString());
            if ($name === 'true' || $name === 'false') {
                return 'bool';
            }
            if ($name === 'null') {
                return 'null';
            }
        }
        if ($expr instanceof Variable && is_string($expr->name)) {
            $binding = $scopeBindings[$expr->name] ?? null;
            if ($binding !== null) {
                $offset = $expr->getStartFilePos();
                if ($offset < 0 || $binding['offset'] <= $offset) {
                    return $binding['type'];
                }
            }
        }
        return null;
    }

    /**
     * Check whether `$actual` satisfies `$expected`.  See the class
     * docblock for the supported shapes.
     */
    private static function isSatisfied(string $actual, string $expected, TypeHierarchy $hierarchy): bool
    {
        $expected = ltrim($expected, '\\');
        $actual = ltrim($actual, '\\');

        if ($expected === '' || $actual === '') {
            return true;
        }
        // Nullable param: null is always OK; non-null is checked
        // against the inner type.
        if (str_starts_with($expected, '?')) {
            if ($actual === 'null') {
                return true;
            }
            return self::isSatisfied($actual, substr($expected, 1), $hierarchy);
        }
        if ($actual === 'null') {
            // Non-nullable param with null arg: explicit mismatch.
            return false;
        }
        if (str_contains($expected, '|')) {
            foreach (explode('|', $expected) as $arm) {
                if (self::isSatisfied($actual, $arm, $hierarchy)) {
                    return true;
                }
            }
            return false;
        }
        if (str_contains($expected, '&')) {
            foreach (explode('&', $expected) as $arm) {
                if (!self::isSatisfied($actual, $arm, $hierarchy)) {
                    return false;
                }
            }
            return true;
        }
        $expectedLower = strtolower($expected);
        if (isset(self::PERMISSIVE_TYPES[$expectedLower])) {
            return true;
        }
        if ($expected === $actual) {
            return true;
        }
        // Scalar param: arg type must match exactly.  `int -> float`
        // promotion is technically allowed by PHP but reporting it
        // is rarely useful as a warning -- accept the literal `int`
        // for a `float` param to avoid false positives.
        if (isset(self::SCALARS[$expectedLower])) {
            if ($expectedLower === 'float' && $actual === 'int') {
                return true;
            }
            if (isset(self::SCALARS[strtolower($actual)])) {
                return $expectedLower === strtolower($actual);
            }
            // Scalar expected, class supplied → mismatch.
            return false;
        }
        // Class-like param with a known scalar argument: a PHP scalar
        // never satisfies a class type hint (no autoboxing), so this is
        // an unambiguous mismatch -- don't defer to the hierarchy (which
        // returns "unknown" for a scalar-vs-class query).
        if (isset(self::SCALARS[strtolower($actual)])) {
            return false;
        }
        // Both sides are class-like.  Defer to the workspace's
        // TypeHierarchy.  Unknown ancestry -> assume OK (don't false-
        // positive on closed-source vendor types).
        $is = $hierarchy->isSubtype($actual, $expected);
        return $is !== false;
    }

    private static function buildMismatchDiagnostic(
        Expr $argExpr,
        PositionMap $positionMap,
        int $oneBasedIndex,
        Param $param,
        string $expectedType,
        string $actualType,
        string $noun,
        string $calleeLabel,
        DiagnosticCode $code,
    ): Diagnostic {
        $paramName = $param->var instanceof Variable && is_string($param->var->name)
            ? '$' . $param->var->name
            : '#' . $oneBasedIndex;
        $message = sprintf(
            '%s %d (%s) of %s expects %s, got %s.',
            $noun,
            $oneBasedIndex,
            $paramName,
            $calleeLabel,
            $expectedType,
            $actualType,
        );
        if ($argExpr->getStartFilePos() >= 0 && $argExpr->getEndFilePos() >= 0) {
            [$sl, $sc, $el, $ec] = $positionMap->rangeFromOffsets(
                $argExpr->getStartFilePos(),
                $argExpr->getEndFilePos() + 1,
            );
        } else {
            [$sl, $sc, $el, $ec] = $positionMap->fullLineRangeFromNikic($argExpr->getStartLine());
        }
        return new Diagnostic(
            startLine: $sl,
            startCharacter: $sc,
            endLine: $el,
            endCharacter: $ec,
            message: $message,
            code: $code,
        );
    }
}
