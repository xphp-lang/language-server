<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\Node\UnionType;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\Substitution;
use XPHP\Transpiler\Monomorphize\TypeParam;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Monomorphization-aware variable type substitution for the LSP.
 *
 * Worse-reflection sees the xphp-stripped source, where `<T>` clauses are
 * blanked to whitespace -- so the type-arg context (`T = User`) baked into
 * `new Collection<User>(...)` is gone by the time it computes the inferred
 * type of a downstream variable.  Result: `$user = $users->first()` shows
 * `?T` (the unresolved placeholder) instead of `?User` (the substituted
 * concrete).
 *
 * This resolver walks the *unstripped* AST that `XphpSourceParser` cached
 * via `ParsedDocumentCache` -- which still carries `ATTR_GENERIC_ARGS` on
 * `new` Name nodes and `ATTR_GENERIC_PARAMS` on ClassLike templates --
 * tracks per-variable bindings, and substitutes via the same
 * `Specializer::substituteTypeRef` the compile-time monomorphizer uses.
 * That keeps LSP-time and compile-time substitution semantics in lockstep
 * (a divergence here would be a bug factory).
 *
 * Scope model (Phase 1.1):
 *
 *   Bindings live in a list of `Scope`s -- one top-level scope covering
 *   the whole document, plus one nested scope per `function` / class
 *   method body.  At lookup time the resolver picks the innermost scope
 *   containing the cursor offset and reads bindings from there.  No
 *   parent-chain yet: two functions with conflicting `$x` types don't
 *   leak across each other, but a closure can't see its outer scope
 *   either (that's Phase 1.5's job).
 *
 * Supported shapes (each contributes a binding into the current scope):
 *
 *   - `$x = new Generic<TypeArgs>(...);`         (top-level or in-function)
 *   - `$y = $x->method(...);`                    (in-scope receiver)
 *   - `function f(Generic<TypeArgs> $param)`     (Phase 1.1)
 *   - `$y = Cls::method<TypeArgs>(...);`         (Phase 1.2)
 *   - `$y = generic_fn<TypeArgs>(...);`          (Phase 1.3)
 *   - `$y = $x->a()->b()->...->c();`             (Phase 1.4: chained calls)
 *   - `function () use ($captured) { ... }`      (Phase 1.5: closure capture)
 *
 * Internally, Phase 1.4 introduced a recursive `inferType(Node, Env)`
 * that types arbitrary expressions -- variables, `new`, method calls,
 * static calls, generic functions -- so chained calls of any depth
 * compose cleanly.  Each call site builds an Env from the current scope's
 * bindings + lookups; the recursion handles n-step chains identically
 * to the 1-step case.
 *
 * Out of scope (returns null, fallback to GenericParamRegistry::prettify):
 *   - arrow functions `fn ($x) => $x->method()` -- different scoping rules
 *   - `$this` binding inside non-static methods
 */
final class GenericResolver
{
    /**
     * @var array<string, array{version: int, scopes: list<array{start: int, end: int, bindings: array<string, VarBinding|ResolvedType>, history: array<string, list<array{pos: int, binding: VarBinding|ResolvedType}>>}>}>
     */
    private array $cache = [];

    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $documents,
        private readonly ClassLikeLookup $classes,
        private readonly XphpSourceParser $parser,
        private readonly FqnIndex $fqnIndex,
    ) {
    }

    /**
     * Return the substituted display string for `$varName` at byte offset
     * `$byteOffset`, or null when the resolver can't model the variable's
     * binding (caller falls back to its existing path).
     */
    public function resolveVariable(string $uri, string $varName, int $byteOffset): ?string
    {
        $binding = $this->bindingAt($uri, $varName, $byteOffset);
        if ($binding === null) {
            return null;
        }
        // A direct `$x = new Generic<...>(...)` produces a VarBinding -- we
        // can render the receiver type with its type-arg list spelt out,
        // e.g. "App\Containers\Collection<App\Models\User>".  The existing
        // path renders just `App\Containers\Collection` (the worse-reflection
        // view); ours adds the type-arg context.
        if ($binding instanceof VarBinding) {
            // Empty paramMap = a non-generic receiver recorded only to type
            // method-call receivers (see buildFromPlainNew).  Render nothing
            // so plain-object hover keeps deferring to worse-reflection.
            if ($binding->paramMap === []) {
                return null;
            }
            return $this->renderBinding($binding);
        }
        return $binding->render();
    }

    /**
     * Resolve the substituted concrete type of `$varName` at `$byteOffset`
     * as a `ResolvedType`.  Mirrors `resolveVariable` but returns the
     * underlying `{TypeRef, nullable}` so the completion path can read
     * `ref->name` directly for `reflectClassLike` without parsing the
     * display string back.
     */
    public function resolveVariableTypeRef(string $uri, string $varName, int $byteOffset): ?ResolvedType
    {
        $binding = $this->bindingAt($uri, $varName, $byteOffset);
        if ($binding === null) {
            return null;
        }
        // VarBinding: variable holds an instance of the generic class
        // itself.  Surface it as a non-nullable TypeRef of the class FQN
        // so the completion path can reflect on the class directly.
        if ($binding instanceof VarBinding) {
            // Empty paramMap = a non-generic receiver recorded only for
            // method-call receiver typing (see buildFromPlainNew).  Defer to
            // worse-reflection, which models nullable/union receivers better.
            if ($binding->paramMap === []) {
                return null;
            }
            return new ResolvedType(new TypeRef($binding->classFqn), false);
        }
        // ResolvedType: variable holds the substituted result of a prior
        // method call.  Return as-is.
        return $binding;
    }

    /**
     * Resolve the substituted-type view of the `MethodCall` enclosing
     * `$byteOffset` -- both the return type AND each parameter type --
     * so hover can render the full signature with the call site's
     * type-args baked in.  Returns null when the cursor isn't on a
     * method-call name, the receiver isn't a tracked variable, or the
     * method can't be located.
     *
     * Parameter substitution mirrors return-type substitution: both
     * read the method's source AST (xphp attributes intact via
     * `ClassLikeLookup`) and substitute through
     * `Specializer::substituteTypeRef`.  Unlike the return type, params
     * have names attached so they're returned as a `name -> type` map.
     * Params whose declared type can't be modelled (e.g. union types)
     * are omitted from the map; caller falls back to prettify for those.
     */
    /**
     * Same shape as `resolveMethodCallSubstitutionAt` but for static calls
     * `Foo::method<T>($x)`.  The "receiver" type-args come from the
     * call's own `ATTR_METHOD_GENERIC_ARGS` (method-scoped generics), not
     * from a bound variable's type.  Returns null when the cursor isn't
     * on a static method-call's name token, when the call isn't generic
     * (no `<T>` annotation), or when class / method lookup fails.
     */
    public function resolveStaticCallSubstitutionAt(string $uri, int $byteOffset): ?MethodCallSubstitution
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $result = $this->documents->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null) {
            return null;
        }
        $call = self::findEnclosingStaticCallNameAt($result->ast, $byteOffset);
        if ($call === null || !$call->class instanceof Name || !$call->name instanceof Identifier) {
            return null;
        }
        $args = $call->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        if (!is_array($args) || $args === []) {
            return null;
        }
        [$useMap, $namespace] = self::useMapAndNamespaceFor($result->ast);
        $classFqn = self::resolveNameWithUseMap($call->class, $useMap, $namespace);
        if ($classFqn === null) {
            return null;
        }
        $classLike = $this->classes->find($classFqn);
        if ($classLike === null) {
            return null;
        }
        $method = self::findMethod($classLike, $call->name->toString());
        if ($method === null) {
            return null;
        }
        $params = $method->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        if (!is_array($params) || count($params) !== count($args)) {
            return null;
        }
        $paramMap = [];
        $paramNames = [];
        foreach ($params as $i => $param) {
            if (!$param instanceof TypeParam || !($args[$i] instanceof TypeRef)) {
                return null;
            }
            $paramMap[$param->name] = $args[$i];
            $paramNames[] = $param->name;
        }
        return self::buildSubstitutionFromMap($method, $paramMap, $paramNames);
    }

    /**
     * Same shape but for free-function calls `identity<T>($x)`.  Type-args
     * come from the call's `ATTR_METHOD_GENERIC_ARGS`; the function's
     * `ATTR_METHOD_GENERIC_PARAMS` provides the names.  Function lookup
     * uses `FqnIndex::functionFor` so filesystem-only declarations work.
     */
    public function resolveFunctionCallSubstitutionAt(string $uri, int $byteOffset): ?MethodCallSubstitution
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $result = $this->documents->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null) {
            return null;
        }
        $call = self::findEnclosingFuncCallNameAt($result->ast, $byteOffset);
        if ($call === null || !$call->name instanceof Name) {
            return null;
        }
        $args = $call->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        if (!is_array($args) || $args === []) {
            return null;
        }
        [$useMap, $namespace] = self::useMapAndNamespaceFor($result->ast);
        $fnFqn = self::resolveNameWithUseMap($call->name, $useMap, $namespace);
        if ($fnFqn === null) {
            return null;
        }
        $function = $this->fqnIndex->functionFor($fnFqn);
        if ($function === null) {
            return null;
        }
        $params = $function->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        if (!is_array($params) || count($params) !== count($args)) {
            return null;
        }
        $paramMap = [];
        $paramNames = [];
        foreach ($params as $i => $param) {
            if (!$param instanceof TypeParam || !($args[$i] instanceof TypeRef)) {
                return null;
            }
            $paramMap[$param->name] = $args[$i];
            $paramNames[] = $param->name;
        }
        return self::buildSubstitutionFromMap($function, $paramMap, $paramNames);
    }

    public function resolveMethodCallSubstitutionAt(string $uri, int $byteOffset): ?MethodCallSubstitution
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $scopes = $this->scopesFor($uri, $item->version, $item->text);
        $bindings = self::bindingsAt($scopes, $byteOffset);

        $result = $this->documents->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null) {
            return null;
        }
        $call = self::findEnclosingMethodCallNameAt($result->ast, $byteOffset);
        if ($call === null) {
            return null;
        }

        // To substitute params (not just the return type) we need both
        // the method's ClassMethod AST node AND the receiver's paramMap.
        // resolveMethodCall encapsulates the receiver lookup; reuse its
        // logic by infer-then-bind manually here.
        $receiverType = self::inferType($call->var, $bindings, $this->classes, $this->fqnIndex, [], '');
        if ($receiverType === null) {
            return null;
        }
        $declaring = $this->classes->findWithContext($receiverType->ref->name);
        if ($declaring === null) {
            return null;
        }
        $classLike = $declaring->classLike;
        if (!$call->name instanceof Identifier) {
            return null;
        }
        // Walk the extends chain so an inherited generic method specializes on a
        // subclass receiver (0.3.0 turbofish on a subclass).
        $found = self::findMethodWithContext($declaring, $call->name->toString(), $this->classes);
        if ($found === null) {
            return null;
        }
        [$method] = $found;
        $paramMap = self::paramMapFromReceiver($classLike, $receiverType);
        $paramMap = self::withMethodTurbofish($paramMap, $call, $method);
        // No type params in scope = a plain method on a non-generic receiver;
        // there is nothing to specialize, so leave the signature to
        // worse-reflection (and don't emit a generic inlay hint for it).
        if ($paramMap === []) {
            return null;
        }
        $paramNames = array_keys($paramMap);

        // Return type.
        $returnTypeRendered = null;
        if ($method->returnType !== null) {
            $tuple = self::returnTypeToRef($method->returnType, $paramNames);
            if ($tuple !== null) {
                [$nullable, $ref] = $tuple;
                $substituted = self::relativeTypeToReceiver($ref, $receiverType)
                    ?? Specializer::substituteTypeRef($ref, Substitution::of($paramMap));
                $returnTypeRendered = (new ResolvedType($substituted, $nullable))->render();
            }
        }

        // Parameter types -- one entry per parameter that has a modelable
        // type annotation.  Params with omitted or unsupported types
        // (union, intersection) get no entry; renderMethod falls back to
        // prettify for them.
        $paramTypes = [];
        foreach ($method->params as $param) {
            $name = $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                ? $param->var->name
                : null;
            if ($name === null || $param->type === null) {
                continue;
            }
            $tuple = self::returnTypeToRef($param->type, $paramNames);
            if ($tuple === null) {
                continue;
            }
            [$nullable, $ref] = $tuple;
            $substituted = Specializer::substituteTypeRef($ref, Substitution::of($paramMap));
            $paramTypes[$name] = (new ResolvedType($substituted, $nullable))->render();
        }

        return new MethodCallSubstitution($returnTypeRendered, $paramTypes);
    }

    /**
     * Locate the declaration of the method called at `$byteOffset`: infer the
     * receiver's class (reusing {@see resolveMethodCallSubstitutionAt()}'s
     * inference) and look the method up xphp-natively via
     * {@see FqnIndex::methodLocation()}. This is the go-to-definition path for
     * generic method calls (`$users->first()` with `$users: Collection<User>`),
     * where the receiver class carries generic syntax (`T[]`, reified `T`) that
     * worse-reflection can't reliably reflect.
     *
     * Returns the location array ({uri, line, char, short}) or null when the
     * cursor isn't on a resolvable method call.
     *
     * @return array{uri: string, line: int, char: int, short: string}|null
     */
    public function resolveMethodDeclarationAt(string $uri, int $byteOffset): ?array
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $scopes = $this->scopesFor($uri, $item->version, $item->text);
        $bindings = self::bindingsAt($scopes, $byteOffset);

        $result = $this->documents->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null) {
            return null;
        }
        $call = self::findEnclosingMethodCallNameAt($result->ast, $byteOffset);
        if ($call === null || !$call->name instanceof Identifier) {
            return null;
        }
        $receiverType = self::inferType($call->var, $bindings, $this->classes, $this->fqnIndex, [], '');
        if ($receiverType === null || $receiverType->ref->isScalar || $receiverType->ref->isTypeParam) {
            return null;
        }
        $fqn = $receiverType->ref->name;
        if ($fqn === '') {
            return null;
        }
        return $this->fqnIndex->methodLocation($fqn, $call->name->toString());
    }

    /**
     * Resolve the FQN of the class that should host a member-access
     * completion at `$byteOffset`.  Generalises the existing
     * `resolveVariableTypeRef` swap in `PhpCompletionResolver`: walks
     * the AST for the innermost expression whose range covers the
     * offset (Variable, MethodCall, StaticCall, FuncCall, New_, ...)
     * and runs `inferType` on it.  Returns the substituted class FQN
     * or null when no usable type can be inferred.
     *
     * This is the completion-side parallel of Phase 0.7's hover/GTD
     * `resolvePropertyReceiverClassAt`: same semantic question -- "what
     * class are we calling/accessing through?" -- different consumer
     * (completion sees mid-edit incomplete source where the property
     * name token doesn't exist yet, so we can't walk for `PropertyFetch`;
     * we walk for the expression whose end is just before the access
     * operator instead).
     */
    public function resolveMemberAccessReceiverClassAt(string $uri, int $byteOffset): ?string
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $scopes = $this->scopesFor($uri, $item->version, $item->text);
        $bindings = self::bindingsAt($scopes, $byteOffset);

        $result = $this->documents->getOrParse($uri, $item->version, $item->text);
        $ast = $result->ast;
        if ($ast === null) {
            try {
                $ast = $this->parser->parseTolerant($item->text);
            } catch (\Throwable) {
                $ast = null;
            }
            if ($ast === null) {
                return null;
            }
        }

        $expr = self::findInnermostExprCovering($ast, $byteOffset);
        if ($expr === null) {
            return null;
        }
        // Completion fires mid-edit, so the innermost expression at the
        // receiverProbe offset may be an incomplete PropertyFetch /
        // NullsafePropertyFetch / MethodCall whose name token is missing
        // (the prefix the user is about to type).  In that case unwrap to
        // the receiver -- the expression we actually want to type for the
        // class lookup.  `inferType` only knows about "complete" leaf
        // shapes; this is the one place that handles the mid-edit
        // partial-AST case.
        if ($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) {
            $expr = $expr->var;
        } elseif ($expr instanceof MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall) {
            // If the method-call's name token is missing OR the cursor sits
            // past the call's closing paren (in a chained access shape),
            // type the call itself.  Otherwise (cursor inside the args)
            // type the receiver.
            if (!$expr->name instanceof Identifier) {
                $expr = $expr->var;
            }
        }
        // A plain non-generic receiver variable -- recorded only to type
        // method-call receivers (see buildFromPlainNew) -- carries no generic
        // context.  Defer to worse-reflection, which models `@var` unions /
        // nullables on the receiver that this single-class view would flatten.
        if ($expr instanceof Variable && is_string($expr->name)) {
            $binding = $bindings[$expr->name] ?? null;
            if ($binding instanceof VarBinding && $binding->paramMap === []) {
                return null;
            }
        }
        $type = self::inferType($expr, $bindings, $this->classes, $this->fqnIndex, [], '');
        if ($type === null) {
            return null;
        }
        $fqn = $type->ref->name;
        if ($fqn === '' || $type->ref->isScalar || $type->ref->isTypeParam) {
            return null;
        }
        return $fqn;
    }

    /**
     * Walk for the innermost Expression node whose `[startFilePos,
     * endFilePos]` covers `$byteOffset`.  Used by member-access
     * completion to find the receiver expression when the cursor sits
     * past the access operator.
     *
     * @param list<Node\Stmt> $ast
     */
    private static function findInnermostExprCovering(array $ast, int $byteOffset): ?Node\Expr
    {
        $visitor = new class($byteOffset) extends NodeVisitorAbstract {
            public ?Node\Expr $best = null;
            private int $bestRange = PHP_INT_MAX;

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if (!$node instanceof Node\Expr) {
                    return null;
                }
                $start = $node->getStartFilePos();
                $end = $node->getEndFilePos();
                if ($start < 0 || $end < 0) {
                    return null;
                }
                if ($this->offset < $start || $this->offset > $end) {
                    return null;
                }
                $range = $end - $start;
                if ($range < $this->bestRange) {
                    $this->best = $node;
                    $this->bestRange = $range;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->best;
    }

    /**
     * Resolve the FQN of the class hosting a property access at
     * `$byteOffset`.  For `$users->first()?->name` (where
     * `$users: Collection<User>` and `Collection<T>::first(): ?T`), the
     * receiver of `?->name` is the substituted method-call result
     * `?User`; this method returns `App\Models\User` so the caller
     * (hover / GTD) can look up the `name` property on the right class.
     *
     * Returns null when the cursor isn't on a property name token, when
     * the receiver expression can't be typed, or when substitution
     * doesn't yield a class (e.g. scalar receiver, or the receiver
     * still resolves to a bare placeholder with no in-scope binding).
     *
     * Used by `PhpHoverResolver::renderProperty` and
     * `PhpDefinitionResolver::locateProperty` to override
     * worse-reflection's view of the receiver class when the resolver
     * has more accurate information.
     */
    public function resolvePropertyReceiverClassAt(string $uri, int $byteOffset): ?string
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $scopes = $this->scopesFor($uri, $item->version, $item->text);
        $bindings = self::bindingsAt($scopes, $byteOffset);

        $result = $this->documents->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null) {
            return null;
        }
        $fetch = self::findEnclosingPropertyFetchNameAt($result->ast, $byteOffset);
        if ($fetch === null) {
            return null;
        }
        $receiverType = self::inferType(
            $fetch->var,
            $bindings,
            $this->classes,
            $this->fqnIndex,
            [],
            '',
        );
        if ($receiverType === null) {
            return null;
        }
        // The class FQN is in `ref->name`; nullability and args don't
        // affect the property's host class (a nullable receiver still
        // accesses the same class's properties).  Scalars and
        // type-params with no concrete binding don't yield a usable
        // FQN -- skip those.
        $fqn = $receiverType->ref->name;
        if ($fqn === '' || $receiverType->ref->isScalar || $receiverType->ref->isTypeParam) {
            return null;
        }
        return $fqn;
    }

    /**
     * Resolve the substituted concrete type of the property access at
     * `$byteOffset`, rendered (e.g. `App\Containers\Pair<App\Models\Plastic,
     * App\Models\User>`).  This is the member-hover parallel of the
     * assignment-tracking path: where `resolvePropertyReceiverClassAt` answers
     * "which class hosts this property", this answers "what concrete type does
     * the property have once the receiver's type-args are substituted in" --
     * so hovering `$pair->first` shows the substituted type instead of the
     * declared type-param `A`.
     *
     * Returns null when the cursor isn't on a property-name token, the
     * receiver isn't a tracked generic instantiation, or the property type
     * isn't a shape we can model (union / intersection -- caller falls back
     * to the unsubstituted render).
     */
    public function resolvePropertyTypeAt(string $uri, int $byteOffset): ?string
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $scopes = $this->scopesFor($uri, $item->version, $item->text);
        $bindings = self::bindingsAt($scopes, $byteOffset);

        $result = $this->documents->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null) {
            return null;
        }
        $fetch = self::findEnclosingPropertyFetchNameAt($result->ast, $byteOffset);
        if ($fetch === null) {
            return null;
        }
        [$useMap, $namespace] = self::useMapAndNamespaceFor($result->ast);
        $resolved = self::resolvePropertyFetch(
            $fetch,
            $bindings,
            $this->classes,
            $this->fqnIndex,
            $useMap,
            $namespace,
        );
        return $resolved?->render();
    }

    /**
     * Walk the AST looking for a `PropertyFetch` or `NullsafePropertyFetch`
     * whose property-name identifier covers `$byteOffset`.  The cursor
     * must land ON the name token; landing on the receiver expression
     * returns null.  Mirrors `findEnclosingMethodCallNameAt`'s shape.
     *
     * @param list<Node\Stmt> $ast
     */
    private static function findEnclosingPropertyFetchNameAt(array $ast, int $byteOffset): PropertyFetch|NullsafePropertyFetch|null
    {
        $visitor = new class($byteOffset) extends NodeVisitorAbstract {
            public PropertyFetch|NullsafePropertyFetch|null $hit = null;

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->hit !== null) {
                    return null;
                }
                if (!$node instanceof PropertyFetch && !$node instanceof NullsafePropertyFetch) {
                    return null;
                }
                $name = $node->name;
                if (!$name instanceof Identifier) {
                    return null;
                }
                $start = $name->getStartFilePos();
                $end = $name->getEndFilePos();
                if ($start < 0 || $end < 0) {
                    return null;
                }
                if ($this->offset >= $start && $this->offset <= $end + 1) {
                    $this->hit = $node;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->hit;
    }

    /**
     * Lookup binding for `$varName` at `$byteOffset` (innermost scope wins).
     */
    private function bindingAt(string $uri, string $varName, int $byteOffset): VarBinding|ResolvedType|null
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $scopes = $this->scopesFor($uri, $item->version, $item->text);
        $bindings = self::bindingsAt($scopes, $byteOffset);
        return $bindings[$varName] ?? null;
    }

    /**
     * Walk the AST looking for a `MethodCall` whose method-name identifier
     * covers `$byteOffset`.  The cursor must land ON the name token --
     * landing on the receiver or the call's args returns null.  Matches
     * IDE behaviour where "hover the method" means hovering its identifier.
     *
     * @param list<Node\Stmt> $ast
     */
    private static function findEnclosingMethodCallNameAt(array $ast, int $byteOffset): MethodCall|NullsafeMethodCall|null
    {
        $visitor = new class($byteOffset) extends NodeVisitorAbstract {
            public MethodCall|NullsafeMethodCall|null $hit = null;

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->hit !== null) {
                    return null;
                }
                if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall) {
                    return null;
                }
                $name = $node->name;
                if (!$name instanceof Identifier) {
                    return null;
                }
                $start = $name->getStartFilePos();
                $end = $name->getEndFilePos();
                if ($start < 0 || $end < 0) {
                    return null;
                }
                // Inclusive end-of-token + 1 for cursor-just-past-end
                // (LSP positions point between characters, not at them).
                if ($this->offset >= $start && $this->offset <= $end + 1) {
                    $this->hit = $node;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->hit;
    }

    /**
     * Mirror of `findEnclosingMethodCallNameAt` for static calls
     * (`Foo::method(...)`).
     *
     * @param list<Node\Stmt> $ast
     */
    private static function findEnclosingStaticCallNameAt(array $ast, int $byteOffset): ?StaticCall
    {
        $visitor = new class($byteOffset) extends NodeVisitorAbstract {
            public ?StaticCall $hit = null;

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->hit !== null || !$node instanceof StaticCall) {
                    return null;
                }
                $name = $node->name;
                if (!$name instanceof Identifier) {
                    return null;
                }
                $start = $name->getStartFilePos();
                $end = $name->getEndFilePos();
                if ($start < 0 || $end < 0) {
                    return null;
                }
                if ($this->offset >= $start && $this->offset <= $end + 1) {
                    $this->hit = $node;
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->hit;
    }

    /**
     * Same shape but for free-function calls (`identity(...)`).
     *
     * @param list<Node\Stmt> $ast
     */
    private static function findEnclosingFuncCallNameAt(array $ast, int $byteOffset): ?Node\Expr\FuncCall
    {
        $visitor = new class($byteOffset) extends NodeVisitorAbstract {
            public ?Node\Expr\FuncCall $hit = null;

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->hit !== null || !$node instanceof Node\Expr\FuncCall) {
                    return null;
                }
                $name = $node->name;
                if (!$name instanceof Name) {
                    return null;
                }
                $start = $name->getStartFilePos();
                $end = $name->getEndFilePos();
                if ($start < 0 || $end < 0) {
                    return null;
                }
                if ($this->offset >= $start && $this->offset <= $end + 1) {
                    $this->hit = $node;
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->hit;
    }

    /**
     * Walk the AST collecting namespace + use map at every position.
     * Returns (useMap, currentNamespace) effective when the document is
     * processed top-to-bottom -- which matches PHP's resolution rules.
     * The maps are file-scoped: the same use stmt may not be in effect
     * elsewhere, but for a single-file resolution this is enough.
     *
     * Public so the ClassLikeLookup implementations can compute a declaring
     * file's name-resolution context for {@see ClassLikeLookup::findWithContext}.
     *
     * @param list<Node\Stmt> $ast
     * @return array{0: array<string, string>, 1: string}
     */
    public static function useMapAndNamespaceFor(array $ast): array
    {
        $useMap = [];
        $namespace = '';
        $visitor = new class($useMap, $namespace) extends NodeVisitorAbstract {
            /** @param array<string, string> $useMap */
            public function __construct(
                public array &$useMap,
                public string &$namespace,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString() ?? '';
                    return null;
                }
                if ($node instanceof Node\Stmt\Use_) {
                    foreach ($node->uses as $useUse) {
                        $alias = $useUse->getAlias()->toString();
                        $this->useMap[$alias] = $useUse->name->toString();
                    }
                    return null;
                }
                if ($node instanceof Node\Stmt\GroupUse) {
                    $prefix = $node->prefix->toString();
                    foreach ($node->uses as $useUse) {
                        $alias = $useUse->getAlias()->toString();
                        $this->useMap[$alias] = $prefix . '\\' . $useUse->name->toString();
                    }
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return [$useMap, $namespace];
    }

    /**
     * Shared body for `resolveStaticCallSubstitutionAt` /
     * `resolveFunctionCallSubstitutionAt` (and conceptually the existing
     * instance-method path).  Given a ClassMethod or Function_ AST node
     * with a precomputed `paramName -> TypeRef` map, render the
     * substituted return type + each substituted parameter type into a
     * `MethodCallSubstitution` value object.
     *
     * @param array<string, TypeRef> $paramMap
     * @param list<string>           $paramNames
     */
    private static function buildSubstitutionFromMap(
        Node\Stmt\ClassMethod|Node\Stmt\Function_ $method,
        array $paramMap,
        array $paramNames,
    ): MethodCallSubstitution {
        $returnTypeRendered = null;
        if ($method->returnType !== null) {
            $tuple = self::returnTypeToRef($method->returnType, $paramNames);
            if ($tuple !== null) {
                [$nullable, $ref] = $tuple;
                $substituted = Specializer::substituteTypeRef($ref, Substitution::of($paramMap));
                $returnTypeRendered = (new ResolvedType($substituted, $nullable))->render();
            }
        }
        $paramTypes = [];
        foreach ($method->params as $param) {
            $name = $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                ? $param->var->name
                : null;
            if ($name === null || $param->type === null) {
                continue;
            }
            $tuple = self::returnTypeToRef($param->type, $paramNames);
            if ($tuple === null) {
                continue;
            }
            [$nullable, $ref] = $tuple;
            $substituted = Specializer::substituteTypeRef($ref, Substitution::of($paramMap));
            $paramTypes[$name] = (new ResolvedType($substituted, $nullable))->render();
        }
        return new MethodCallSubstitution($returnTypeRendered, $paramTypes);
    }

    /**
     * @return list<array{start: int, end: int, bindings: array<string, VarBinding|ResolvedType>, history: array<string, list<array{pos: int, binding: VarBinding|ResolvedType}>>}>
     */
    private function scopesFor(string $uri, int $version, string $text): array
    {
        if (isset($this->cache[$uri]) && $this->cache[$uri]['version'] === $version) {
            return $this->cache[$uri]['scopes'];
        }
        $result = $this->documents->getOrParse($uri, $version, $text);
        $ast = $result->ast;
        if ($ast === null) {
            // The cache's strict parse failed -- typical when the user is
            // mid-edit (e.g. cursor on `$u->` with no terminator).  Fall
            // back to tolerant parsing so we still see the completed
            // statements ABOVE the broken line (which is where the `new
            // Generic<...>(...)` binding usually lives).  Without this
            // fallback every completion the user triggers while typing
            // resets bindings to empty.
            try {
                $ast = $this->parser->parseTolerant($text);
            } catch (\Throwable) {
                $ast = null;
            }
        }
        $scopes = $ast === null ? self::emptyScopes() : $this->build($ast);
        $this->cache[$uri] = ['version' => $version, 'scopes' => $scopes];
        return $scopes;
    }

    /**
     * @return list<array{start: int, end: int, bindings: array<string, VarBinding|ResolvedType>, history: array<string, list<array{pos: int, binding: VarBinding|ResolvedType}>>}>
     */
    private static function emptyScopes(): array
    {
        return [['start' => 0, 'end' => PHP_INT_MAX, 'bindings' => [], 'history' => []]];
    }

    /**
     * Pick the innermost (narrowest-range) scope whose `[start, end]`
     * contains `$offset`.  Top-level scope is the catch-all -- it always
     * matches and has the widest range, so any nested function scope
     * wins on overlap.
     *
     * @param list<array{start: int, end: int, bindings: array<string, VarBinding|ResolvedType>, history: array<string, list<array{pos: int, binding: VarBinding|ResolvedType}>>}> $scopes
     * @return array<string, VarBinding|ResolvedType>
     */
    public static function bindingsAt(array $scopes, int $offset): array
    {
        $best = null;
        foreach ($scopes as $scope) {
            if ($offset < $scope['start'] || $offset > $scope['end']) {
                continue;
            }
            if ($best === null || ($scope['end'] - $scope['start']) < ($best['end'] - $best['start'])) {
                $best = $scope;
            }
        }
        if ($best === null) {
            return [];
        }
        // Flow-sensitive: for each variable, return the binding from the nearest
        // assignment AT OR BEFORE $offset, so a later reassignment to a different
        // type doesn't leak backwards onto an earlier hover (a var reassigned
        // `$x = ...::<string>()` then `$x = ...::<int>()` reads as the right type
        // at each site). Seeded params/uses carry the scope-start position.
        $out = [];
        foreach ($best['history'] as $name => $entries) {
            $chosen = null;
            foreach ($entries as $entry) {
                // @infection-ignore-all GreaterThanOrEqualTo -- distinct assignments
                // have distinct positions, so `>=` vs `>` never differ (no ties).
                if ($entry['pos'] <= $offset && ($chosen === null || $entry['pos'] >= $chosen['pos'])) {
                    $chosen = $entry;
                }
            }
            if ($chosen !== null) {
                $out[$name] = $chosen['binding'];
            }
        }
        return $out;
    }

    /**
     * Build the list of scopes for a document.  Always returns at least
     * one (top-level) scope.
     *
     * @param list<Node\Stmt> $ast
     * @return list<array{start: int, end: int, bindings: array<string, VarBinding|ResolvedType>, history: array<string, list<array{pos: int, binding: VarBinding|ResolvedType}>>}>
     */
    private function build(array $ast): array
    {
        // Pre-allocate the catch-all top-level scope.  Function bodies
        // get nested scopes appended during the walk.
        $scopes = self::emptyScopes();
        $stack = [0]; // index into $scopes; top is the current scope
        // Per-document use map: short-name alias -> fully-qualified name.
        // Populated as we walk Use_ statements; consulted by static-call
        // resolution when the cursor's `Cls::method<...>(...)` references
        // a class by its imported short name (which nikic doesn't qualify
        // for us -- the LSP doesn't run NameResolver to keep AST mutation
        // cheap).
        $useMap = [];
        $currentNamespace = '';

        $classes = $this->classes;
        $fqnIndex = $this->fqnIndex;

        $visitor = new class($scopes, $stack, $useMap, $currentNamespace, $classes, $fqnIndex) extends NodeVisitorAbstract {
            /**
             * @param list<array{start: int, end: int, bindings: array<string, VarBinding|ResolvedType>, history: array<string, list<array{pos: int, binding: VarBinding|ResolvedType}>>}> $scopes
             * @param list<int> $stack
             * @param array<string, string> $useMap
             */
            public function __construct(
                public array &$scopes,
                public array &$stack,
                public array &$useMap,
                public string &$currentNamespace,
                private readonly ClassLikeLookup $classes,
                private readonly FqnIndex $fqnIndex,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                if ($node instanceof Use_) {
                    foreach ($node->uses as $u) {
                        if (!$u instanceof UseItem) {
                            continue;
                        }
                        $fqn = $u->name->toString();
                        $alias = $u->alias?->toString() ?? self::lastSegment($fqn);
                        $this->useMap[$alias] = $fqn;
                    }
                    return null;
                }

                // Open a new scope for function-like declarations.  Their
                // parameters seed bindings; the body Assigns then go into
                // the same scope.
                if ($node instanceof Function_ || $node instanceof ClassMethod) {
                    $start = $node->getStartFilePos();
                    $end = $node->getEndFilePos();
                    if ($start < 0 || $end < 0) {
                        return null;
                    }
                    $seeded = self::seedFromParams($node->params, $this->classes);
                    $this->scopes[] = [
                        'start' => $start,
                        'end' => $end,
                        'bindings' => $seeded,
                        'history' => self::seedHistory($seeded, $start),
                    ];
                    $this->stack[] = count($this->scopes) - 1;
                    return null;
                }

                // Closure: like a function, but also inherits explicit
                // outer-scope bindings via the `use (...)` clause.  Phase 1.5.
                if ($node instanceof Closure) {
                    $start = $node->getStartFilePos();
                    $end = $node->getEndFilePos();
                    if ($start < 0 || $end < 0) {
                        return null;
                    }
                    $outerBindings = $this->currentBindings();
                    $closureBindings = self::seedFromParams($node->params, $this->classes);
                    foreach ($node->uses as $use) {
                        if (!$use instanceof ClosureUse) {
                            continue;
                        }
                        if (!$use->var instanceof Variable || !is_string($use->var->name)) {
                            continue;
                        }
                        $varName = $use->var->name;
                        if (isset($outerBindings[$varName])) {
                            $closureBindings[$varName] = $outerBindings[$varName];
                        }
                    }
                    $this->scopes[] = [
                        'start' => $start,
                        'end' => $end,
                        'bindings' => $closureBindings,
                        'history' => self::seedHistory($closureBindings, $start),
                    ];
                    $this->stack[] = count($this->scopes) - 1;
                    return null;
                }

                if ($node instanceof Assign) {
                    $this->handleAssign($node);
                }
                return null;
            }

            private static function lastSegment(string $fqn): string
            {
                $pos = strrpos($fqn, '\\');
                return $pos === false ? $fqn : substr($fqn, $pos + 1);
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof Closure) {
                    array_pop($this->stack);
                }
                return null;
            }

            private function handleAssign(Assign $node): void
            {
                $lhs = $node->var;
                if (!$lhs instanceof Variable || !is_string($lhs->name)) {
                    return;
                }
                $name = $lhs->name;
                // Position of this assignment's LHS: the binding it produces is
                // in effect for hovers AT or AFTER this point, until the next
                // reassignment. Enables flow-sensitive lookup (see bindingsAt).
                $pos = $lhs->getStartFilePos();
                $rhs = $node->expr;

                if ($rhs instanceof New_) {
                    // Generic `new Foo<...>()` -> binding with a paramMap;
                    // non-generic `new Foo()` -> class-only binding (empty
                    // paramMap) so method calls on the receiver still resolve.
                    $binding = GenericResolver::buildFromNew($rhs, $this->classes)
                        ?? GenericResolver::buildFromPlainNew(
                            $rhs,
                            $this->useMap,
                            $this->currentNamespace,
                            $this->classes,
                        );
                    if ($binding !== null) {
                        $this->writeBinding($name, $binding, $pos);
                    }
                    return;
                }
                if ($rhs instanceof MethodCall || $rhs instanceof NullsafeMethodCall) {
                    $resolved = GenericResolver::resolveMethodCall(
                        $rhs,
                        $this->currentBindings(),
                        $this->classes,
                        $this->fqnIndex,
                        $this->useMap,
                        $this->currentNamespace,
                    );
                    if ($resolved !== null) {
                        $this->writeBinding($name, $resolved, $pos);
                    }
                    return;
                }
                if ($rhs instanceof StaticCall) {
                    $resolved = GenericResolver::resolveStaticCall(
                        $rhs,
                        $this->classes,
                        $this->useMap,
                        $this->currentNamespace,
                    );
                    if ($resolved !== null) {
                        $this->writeBinding($name, $resolved, $pos);
                    }
                    return;
                }
                if ($rhs instanceof FuncCall) {
                    $resolved = GenericResolver::resolveFuncCall($rhs, $this->fqnIndex);
                    if ($resolved !== null) {
                        $this->writeBinding($name, $resolved, $pos);
                    }
                    return;
                }
                if ($rhs instanceof PropertyFetch || $rhs instanceof NullsafePropertyFetch) {
                    $resolved = GenericResolver::resolvePropertyFetch(
                        $rhs,
                        $this->currentBindings(),
                        $this->classes,
                        $this->fqnIndex,
                        $this->useMap,
                        $this->currentNamespace,
                    );
                    if ($resolved !== null) {
                        $this->writeBinding($name, $resolved, $pos);
                    }
                }
            }

            /**
             * @return array<string, VarBinding|ResolvedType>
             */
            private function currentBindings(): array
            {
                $idx = $this->stack[count($this->stack) - 1];
                return $this->scopes[$idx]['bindings'];
            }

            private function writeBinding(string $name, VarBinding|ResolvedType $binding, int $pos): void
            {
                $idx = $this->stack[count($this->stack) - 1];
                // `bindings` is the accumulated (last-wins) map used to resolve
                // later RHS expressions DURING the walk. `history` additionally
                // records each assignment's position so a QUERY can pick the
                // binding in effect at a given offset (flow-sensitive).
                $this->scopes[$idx]['bindings'][$name] = $binding;
                $this->scopes[$idx]['history'][$name][] = ['pos' => $pos, 'binding' => $binding];
            }

            /**
             * Seed flow-history from an initial binding map (function params,
             * closure uses) so those bindings are visible from the scope's start.
             *
             * @param  array<string, VarBinding|ResolvedType> $bindings
             * @return array<string, list<array{pos: int, binding: VarBinding|ResolvedType}>>
             */
            private static function seedHistory(array $bindings, int $pos): array
            {
                $history = [];
                foreach ($bindings as $name => $binding) {
                    $history[$name] = [['pos' => $pos, 'binding' => $binding]];
                }
                return $history;
            }

            /**
             * Build initial bindings from a function's parameters.
             *
             * @param list<Param> $params
             * @return array<string, VarBinding|ResolvedType>
             */
            private static function seedFromParams(array $params, ClassLikeLookup $classes): array
            {
                $bindings = [];
                foreach ($params as $param) {
                    if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                        continue;
                    }
                    $type = $param->type;
                    // Nullable wrapper: `?Collection<User> $users` carries
                    // the Name we want underneath.  We track the binding
                    // without nullability -- VarBinding only encodes the
                    // class + paramMap, and a nullable receiver still
                    // dispatches to the same class's methods.
                    if ($type instanceof NullableType) {
                        $type = $type->type;
                    }
                    if (!$type instanceof Name) {
                        continue;
                    }
                    $binding = GenericResolver::buildFromName($type, $classes);
                    if ($binding !== null) {
                        $bindings[$param->var->name] = $binding;
                    }
                }
                return $bindings;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->scopes;
    }

    /**
     * Build a `VarBinding` from a `new Generic<TypeArgs>(...)` site.
     * Returns null when the class isn't a known template, the args don't
     * match the params, or the class declaration can't be located.
     *
     * @internal called from the visitor closure.
     */
    public static function buildFromNew(New_ $new, ClassLikeLookup $classes): ?VarBinding
    {
        $class = $new->class;
        if (!$class instanceof Name) {
            return null;
        }
        return self::buildFromName($class, $classes);
    }

    /**
     * Build a class-only `VarBinding` (empty paramMap) for a NON-generic
     * `new Foo()`.  This records the receiver's class FQN so method calls on
     * the variable can resolve -- in particular a generic-method turbofish on
     * a non-generic receiver, `$u->identity::<int>(...)`, where the binding's
     * job is purely to type the receiver `$u` as `Foo`; the method's own `T`
     * is bound later from the call site.
     *
     * resolveVariable() deliberately renders nothing for empty-paramMap
     * bindings, so plain-object hover still defers to worse-reflection -- this
     * only feeds receiver inference and member completion.
     *
     * @param array<string, string> $useMap
     */
    public static function buildFromPlainNew(
        New_ $new,
        array $useMap,
        string $currentNamespace,
        ClassLikeLookup $classes,
    ): ?VarBinding {
        if (!$new->class instanceof Name) {
            return null;
        }
        $fqn = self::resolveNameWithUseMap($new->class, $useMap, $currentNamespace);
        if ($fqn === null || $classes->find($fqn) === null) {
            return null;
        }
        return new VarBinding($fqn, []);
    }

    /**
     * Build a `VarBinding` from a `Name` node carrying
     * `ATTR_TEMPLATE_FQN` + `ATTR_GENERIC_ARGS`.  Shared between the
     * `new Generic<...>(...)` call site and the parameter-type-hint
     * extraction in `seedFromParams`.
     *
     * @internal called from the visitor closure.
     */
    public static function buildFromName(Name $name, ClassLikeLookup $classes): ?VarBinding
    {
        $templateFqn = $name->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
        $args = $name->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
        if (!is_string($templateFqn) || !is_array($args) || $args === []) {
            return null;
        }
        $classLike = $classes->find($templateFqn);
        if ($classLike === null) {
            return null;
        }
        $params = $classLike->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        if (!is_array($params) || count($params) !== count($args)) {
            return null;
        }
        $paramMap = [];
        foreach ($params as $i => $param) {
            if (!$param instanceof TypeParam || !($args[$i] instanceof TypeRef)) {
                return null;
            }
            $paramMap[$param->name] = $args[$i];
        }
        return new VarBinding($templateFqn, $paramMap);
    }

    /**
     * Recursive expression typer.  Introduced in Phase 1.4 to type
     * arbitrary expressions so chained calls (`$a->b()->c()`) compose
     * without a separate code path for each chain depth.  Every other
     * call-site type resolver (resolveMethodCall, resolveStaticCall,
     * resolveFuncCall) is a leaf of this dispatch -- exactly one
     * shape per leaf, no nesting.
     *
     * Returns null when the expression's type can't be modelled (the
     * caller falls back to its existing path).  The recursion stays
     * bounded by AST depth -- pathological chains are very unusual in
     * real source, and infinite cycles are impossible because the AST
     * is a tree.
     *
     * @param array<string, VarBinding|ResolvedType> $bindings
     * @param array<string, string> $useMap
     */
    public static function inferType(
        Node $expr,
        array $bindings,
        ClassLikeLookup $classes,
        FqnIndex $fqnIndex,
        array $useMap,
        string $currentNamespace,
    ): ?ResolvedType {
        if ($expr instanceof Variable && is_string($expr->name)) {
            $binding = $bindings[$expr->name] ?? null;
            if ($binding === null) {
                return null;
            }
            if ($binding instanceof VarBinding) {
                return self::resolvedTypeFromBinding($binding);
            }
            return $binding;
        }
        if ($expr instanceof New_) {
            $varBinding = self::buildFromNew($expr, $classes);
            return $varBinding === null ? null : self::resolvedTypeFromBinding($varBinding);
        }
        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
            return self::resolveMethodCall($expr, $bindings, $classes, $fqnIndex, $useMap, $currentNamespace);
        }
        if ($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) {
            // A property-fetch receiver (`$a?->b?->c`): delegate to
            // resolvePropertyFetch, which recurses into its own receiver via
            // inferType. This terminates at a Variable / New_ / *Call leaf
            // (each hop strips one AST node), so deep property chains resolve
            // and per-hop nullsafe nullability propagates.
            return self::resolvePropertyFetch($expr, $bindings, $classes, $fqnIndex, $useMap, $currentNamespace);
        }
        if ($expr instanceof StaticCall) {
            return self::resolveStaticCall($expr, $classes, $useMap, $currentNamespace);
        }
        if ($expr instanceof FuncCall) {
            return self::resolveFuncCall($expr, $fqnIndex);
        }
        return null;
    }

    /**
     * Conservative null-dereference detection over a whole document.
     *
     * Flags a plain-`->` property/method access whose IMMEDIATE receiver is a
     * statically nullable member-access sub-expression -- a chain like
     * `$users->first()->name` where `first()` returns `?User`. In that shape no
     * inline null-guard is syntactically possible and there is no bare variable
     * to flow-narrow, so the inferred nullability is a reliable signal.
     *
     * Deliberately narrow to keep false positives near zero:
     *   - only plain `->` accesses (a `?->` access is already null-safe);
     *   - the receiver must itself be a `->`/`?->` method-call or property-fetch
     *     (bare-variable receivers like `$x->y` are DEFERRED to a future
     *     flow-narrowing pass -- guards such as `if ($x !== null)` aren't modelled);
     *   - when `inferType` can't model the receiver it returns null and nothing
     *     fires (unresolvable cross-file types degrade safely to no diagnostic).
     *
     * Returns sites in STRIPPED-source byte offsets; the diagnostics layer maps
     * them back to the original buffer.
     *
     * @return list<NullDerefSite>
     */
    public function findNullDerefSites(string $uri, int $version, string $text): array
    {
        $result = $this->documents->getOrParse($uri, $version, $text);
        $ast = $result->ast;
        if ($ast === null) {
            return [];
        }
        $scopes = $this->scopesFor($uri, $version, $text);
        [$useMap, $namespace] = self::useMapAndNamespaceFor($ast);

        $sites = [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class(
            $sites,
            $scopes,
            $this->classes,
            $this->fqnIndex,
            $useMap,
            $namespace,
        ) extends NodeVisitorAbstract {
            /**
             * @param list<NullDerefSite>                                                                       $sites
             * @param list<array{start: int, end: int, bindings: array<string, VarBinding|ResolvedType>, history: array<string, list<array{pos: int, binding: VarBinding|ResolvedType}>>}>       $scopes
             * @param array<string, string>                                                                     $useMap
             */
            public function __construct(
                private array &$sites,
                private array $scopes,
                private ClassLikeLookup $classes,
                private FqnIndex $fqnIndex,
                private array $useMap,
                private string $namespace,
            ) {
            }

            public function enterNode(Node $node): null
            {
                // Only plain `->` accesses; `?->` is already null-safe.
                if (!$node instanceof PropertyFetch && !$node instanceof MethodCall) {
                    return null;
                }
                if (!$node->name instanceof Identifier) {
                    return null;
                }
                $receiver = $node->var;
                // Restrict to member-access receivers: a chained sub-expression
                // that can't carry an inline guard and isn't a narrowable bare
                // variable. (`$x->y` on a nullable `$x` is deferred.)
                if (
                    !$receiver instanceof MethodCall
                    && !$receiver instanceof NullsafeMethodCall
                    && !$receiver instanceof PropertyFetch
                    && !$receiver instanceof NullsafePropertyFetch
                ) {
                    return null;
                }
                $bindings = GenericResolver::bindingsAt($this->scopes, $receiver->getStartFilePos());
                $receiverType = GenericResolver::inferType(
                    $receiver,
                    $bindings,
                    $this->classes,
                    $this->fqnIndex,
                    $this->useMap,
                    $this->namespace,
                );
                if ($receiverType === null || !$receiverType->nullable) {
                    return null;
                }
                $start = $node->name->getStartFilePos();
                $end = $node->name->getEndFilePos();
                if ($start < 0 || $end < $start) {
                    return null;
                }
                $this->sites[] = new NullDerefSite(
                    $start,
                    $end,
                    $node->name->toString(),
                    $receiverType->render(),
                );
                return null;
            }
        });
        $traverser->traverse($ast);

        return $sites;
    }

    /**
     * Convert a `VarBinding` (class + paramMap) into a `ResolvedType` -- a
     * `TypeRef` whose `args` carry the bound type-args so downstream
     * `inferType` calls can recover the paramMap for further chained
     * substitutions.  Without this round-trip the receiver of a chained
     * call would lose its generic context.
     */
    private static function resolvedTypeFromBinding(VarBinding $binding): ResolvedType
    {
        $args = array_values($binding->paramMap);
        return new ResolvedType(new TypeRef($binding->classFqn, $args), false);
    }

    /**
     * Resolve a `$x->method(...)` RHS by typing the receiver via
     * `inferType` then substituting the method's return type with the
     * receiver's type-arg bindings.  Receiver can be any expression --
     * Variable (1-step), MethodCall (n-step chain), etc.
     *
     * @param array<string, VarBinding|ResolvedType> $bindings
     * @param array<string, string> $useMap
     * @internal called from the visitor closure and from inferType.
     */
    public static function resolveMethodCall(
        MethodCall|NullsafeMethodCall $call,
        array $bindings,
        ClassLikeLookup $classes,
        FqnIndex $fqnIndex,
        array $useMap = [],
        string $currentNamespace = '',
    ): ?ResolvedType {
        if (!$call->name instanceof Identifier) {
            return null;
        }
        $receiverType = self::inferType(
            $call->var,
            $bindings,
            $classes,
            $fqnIndex,
            $useMap,
            $currentNamespace,
        );
        if ($receiverType === null) {
            return null;
        }
        // findWithContext (not find) so a bare class name in the return type
        // can be qualified against the method's DECLARING file (W1/W2).
        $declaring = $classes->findWithContext($receiverType->ref->name);
        if ($declaring === null) {
            return null;
        }
        $classLike = $declaring->classLike;
        // Walk the extends chain so a generic method inherited from a base class
        // resolves on a subclass receiver (0.3.0). `$methodContext` is the
        // DECLARING class's context -- used to qualify the return type against
        // the file that declared the method, not the subclass's file.
        $found = self::findMethodWithContext($declaring, $call->name->toString(), $classes);
        if ($found === null) {
            return null;
        }
        [$method, $methodContext] = $found;
        $returnType = $method->returnType;
        if ($returnType === null) {
            return null;
        }
        // Rebuild paramMap from the receiver's TypeRef args. Empty for a plain
        // method on a non-generic receiver -- substitution is then a no-op.
        $paramMap = self::paramMapFromReceiver($classLike, $receiverType);
        $paramMap = self::withMethodTurbofish($paramMap, $call, $method);
        $isGeneric = $paramMap !== [];
        $paramNames = array_keys($paramMap);

        [$nullable, $ref] = self::returnTypeToRef($returnType, $paramNames) ?? [null, null];
        if ($ref === null) {
            return null;
        }
        // `static`/`self`/`parent` bind to the receiver's concrete type.
        $relative = self::relativeTypeToReceiver($ref, $receiverType);
        $substituted = $relative
            ?? ($isGeneric ? Specializer::substituteTypeRef($ref, Substitution::of($paramMap)) : $ref);
        $substituted = self::qualifyTypeRef($substituted, $methodContext, $classes);

        // Gap 2: a non-generic method used to bail to null (defer to
        // worse-reflection). Now we OWN it -- so chains continue
        // (`$users->first()?->self()?->name`) -- but ONLY when the result is a
        // usable receiver: a relative type, or a class the lookup confirms.
        // Scalars / unconfirmable names still return null so terminal hovers
        // and single non-generic calls keep worse-reflection's richer view.
        if (!$isGeneric && $relative === null) {
            $confirmable = !$substituted->isScalar
                && !$substituted->isTypeParam
                && $substituted->name !== ''
                && $classes->find(ltrim($substituted->name, '\\')) !== null;
            if (!$confirmable) {
                return null;
            }
        }

        // A nullsafe call (`$x?->method()`) short-circuits to null when the
        // receiver is null, so the result is nullable on top of the method's
        // own return-type nullability.
        $resultNullable = $nullable || $call instanceof NullsafeMethodCall;
        return new ResolvedType($substituted, $resultNullable);
    }

    /**
     * Resolve `$receiver->propName` to a substituted concrete type by:
     *   1. inferring the receiver's type (typically a `VarBinding`);
     *   2. locating the property declaration on the receiver's class --
     *      both regular `Property` declarations AND
     *      constructor-promoted `public T $item` params;
     *   3. substituting the property's type via the receiver's
     *      paramMap (e.g. `T` → `Tag` for `StringableBox<Tag>`).
     *
     * Returns null when the receiver isn't tracked, the property isn't
     * declared on the class, or the property's type isn't a shape we
     * can model (union / intersection -- those fall back to prettify).
     *
     * @param array<string, VarBinding|ResolvedType> $bindings
     * @param array<string, string>                  $useMap
     */
    public static function resolvePropertyFetch(
        PropertyFetch|NullsafePropertyFetch $fetch,
        array $bindings,
        ClassLikeLookup $classes,
        FqnIndex $fqnIndex,
        array $useMap = [],
        string $currentNamespace = '',
    ): ?ResolvedType {
        if (!$fetch->name instanceof Identifier) {
            return null;
        }
        $receiverType = self::inferType(
            $fetch->var,
            $bindings,
            $classes,
            $fqnIndex,
            $useMap,
            $currentNamespace,
        );
        if ($receiverType === null) {
            return null;
        }
        // findWithContext (not find) so we get the DECLARING file's use-map +
        // namespace, needed to qualify a bare class name in the property type
        // against the file where the property is declared -- incl. cross-namespace
        // `use` imports.
        $declaring = $classes->findWithContext($receiverType->ref->name);
        if ($declaring === null) {
            return null;
        }
        $classLike = $declaring->classLike;
        $propertyType = self::findPropertyType($classLike, $fetch->name->toString());
        if ($propertyType === null) {
            return null;
        }
        $paramMap = self::paramMapFromReceiver($classLike, $receiverType);
        $paramNames = array_keys($paramMap);
        // returnTypeToRef is the right shape: it handles nullable, plain
        // names, ATTR_TEMPLATE_FQN-tagged generic refs, and bare TypeParam
        // identifiers (`T`).  Property types share the same node shapes
        // as return types so we reuse the helper.
        [$nullable, $ref] = self::returnTypeToRef($propertyType, $paramNames) ?? [null, null];
        if ($ref === null) {
            return null;
        }
        $substituted = Specializer::substituteTypeRef($ref, Substitution::of($paramMap));
        // A property typed as a bare class name (`?User`) resolves to an
        // UNqualified TypeRef (the resolver runs no NameResolver). That renders
        // fine for a terminal hover, but a chained access
        // (`$x?->bestFriend?->name`) needs the intermediate's class FQN so the
        // next hop can look the class up. Qualify it against the declaring
        // file's use-map + namespace.
        $substituted = self::qualifyTypeRef($substituted, $declaring, $classes);
        // A nullsafe access (`$x?->prop`) short-circuits to null when the
        // receiver is null, so the result is `<propType>|null` regardless of
        // the property's own declared nullability. A regular `->` does NOT
        // widen (a null receiver there is a runtime error, not a type).
        $resultNullable = $nullable || $fetch instanceof NullsafePropertyFetch;
        return new ResolvedType($substituted, $resultNullable);
    }

    /**
     * A bare (non-scalar, non-type-param) class name in a member type --
     * `?User` -- comes back unqualified because the resolver runs no
     * NameResolver. Qualify it against the DECLARING file's name-resolution
     * context (use-map + namespace), so a cross-namespace `use App\Models\User`
     * resolves correctly and not just same-namespace references. Accept the
     * candidate ONLY when the lookup confirms it exists -- an unconfirmable name
     * is left bare so a chain degrades to null rather than fabricating a wrong FQN.
     */
    private static function qualifyTypeRef(TypeRef $ref, ClassLikeContext $declaring, ClassLikeLookup $classes): TypeRef
    {
        if ($ref->isScalar || $ref->isTypeParam) {
            return $ref;
        }
        $name = $ref->name;
        // Already namespaced / fully-qualified, or resolvable as-is.
        if ($name === '' || str_contains($name, '\\') || $classes->find($name) !== null) {
            return $ref;
        }
        $candidate = self::resolveNameWithUseMap(new Name($name), $declaring->useMap, $declaring->namespace);
        if ($candidate === null || $candidate === $name || $classes->find($candidate) === null) {
            return $ref; // can't confirm -- leave bare (safe degradation)
        }
        return new TypeRef($candidate, $ref->args, $ref->isScalar, $ref->isTypeParam);
    }

    /**
     * Locate `$propName` on `$classLike` and return its declared type
     * node.  Checks BOTH:
     *   - regular `Property` declarations inside the class body, AND
     *   - constructor-promoted `public T $item` params (which expand
     *     into properties at PHP 8.0+ syntax level).
     *
     * Returns null when the property is undeclared or has no type hint.
     */
    private static function findPropertyType(ClassLike $classLike, string $propName): ?Node
    {
        foreach ($classLike->stmts as $member) {
            if ($member instanceof Node\Stmt\Property) {
                foreach ($member->props as $prop) {
                    if (strcasecmp($prop->name->toString(), $propName) === 0) {
                        return $member->type;
                    }
                }
                continue;
            }
            if ($member instanceof ClassMethod && strcasecmp($member->name->toString(), '__construct') === 0) {
                foreach ($member->params as $param) {
                    // PHP 8 constructor-promoted properties: any
                    // visibility / `readonly` modifier on a ctor param
                    // also declares a class property with the same
                    // name + type.
                    if ($param->flags === 0) {
                        continue;
                    }
                    if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                        continue;
                    }
                    if (strcasecmp($param->var->name, $propName) === 0) {
                        return $param->type;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Rebuild `paramName => TypeRef` from a class's `ATTR_GENERIC_PARAMS`
     * zipped with the receiver `ResolvedType`'s `args`.  Returns an empty
     * array when the class is non-generic or when the receiver carries
     * no args.
     *
     * @return array<string, TypeRef>
     */
    private static function paramMapFromReceiver(ClassLike $classLike, ResolvedType $receiver): array
    {
        $params = $classLike->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        if (!is_array($params) || $params === []) {
            return [];
        }
        $args = $receiver->ref->args;
        $map = [];
        foreach ($params as $i => $p) {
            if (!$p instanceof TypeParam) {
                continue;
            }
            if (!isset($args[$i]) || !$args[$i] instanceof TypeRef) {
                continue;
            }
            $map[$p->name] = $args[$i];
        }
        return $map;
    }

    /**
     * Merge a generic method's OWN turbofish bindings into a paramMap.
     *
     * `$obj->identity::<string>(...)` carries the explicit type args on the
     * MethodCall node (`ATTR_METHOD_GENERIC_ARGS`); the method declaration
     * carries the matching type params (`ATTR_METHOD_GENERIC_PARAMS`).
     * Zipping them binds the method's own `T` to the call-site type, layered
     * ON TOP of any class-level params already in `$paramMap` (a generic
     * method on a generic class references both scopes).  No-op when the call
     * has no turbofish or the arity doesn't match.
     *
     * Without this, `resolveMethodCall` substitutes the return type against
     * only the receiver's class params -- so a generic method on a NON-generic
     * receiver (`Util::identity<T>`) leaves `T` unbound.  The static-call
     * paths (`resolveStaticCall` / `resolveStaticCallSubstitutionAt`) already
     * do exactly this; this is the instance-call equivalent.
     *
     * @param array<string, TypeRef> $paramMap
     * @return array<string, TypeRef>
     */
    private static function withMethodTurbofish(array $paramMap, Node $call, ClassMethod $method): array
    {
        $args = $call->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        if (!is_array($args) || $args === []) {
            return $paramMap;
        }
        $params = $method->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        if (!is_array($params) || count($params) !== count($args)) {
            return $paramMap;
        }
        foreach ($params as $i => $param) {
            if ($param instanceof TypeParam && $args[$i] instanceof TypeRef) {
                $paramMap[$param->name] = $args[$i];
            }
        }
        return $paramMap;
    }

    /**
     * Resolve a relative type (`static` / `self` / `$this`) to the receiver's
     * concrete type.  A method declared `: static` (or `: self`) returns an
     * instance of the receiver's class, so `$a->fresh()` on `$a: Builder<int>`
     * is `Builder<int>` -- not the literal keyword.  `Specializer` only swaps
     * type *params*, so without this the relative keyword passes through
     * unsubstituted.  Returns null when `$ref` isn't relative, so the caller
     * falls back to normal type-param substitution.
     */
    private static function relativeTypeToReceiver(TypeRef $ref, ResolvedType $receiver): ?TypeRef
    {
        return in_array(strtolower($ref->name), ['static', 'self', '$this'], true)
            ? $receiver->ref
            : null;
    }

    /**
     * Resolve a `Cls::method<TypeArgs>(...)` RHS.  Reads the class via the
     * per-document use map (xphp's parser doesn't run nikic's NameResolver
     * so single-segment Names stay un-qualified at LSP time), finds the
     * method's `ATTR_METHOD_GENERIC_PARAMS`, zips with the call site's
     * `ATTR_METHOD_GENERIC_ARGS`, and substitutes the return type.
     *
     * Returns null when the class can't be located, the method isn't
     * generic, or the args don't match the param arity.
     *
     * @param array<string, string> $useMap   alias -> FQN
     * @internal called from the visitor closure.
     */
    public static function resolveStaticCall(
        StaticCall $call,
        ClassLikeLookup $classes,
        array $useMap,
        string $currentNamespace,
    ): ?ResolvedType {
        if (!$call->class instanceof Name) {
            return null;
        }
        if (!$call->name instanceof Identifier) {
            return null;
        }
        $args = $call->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        if (!is_array($args) || $args === []) {
            return null;
        }
        $classFqn = self::resolveNameWithUseMap($call->class, $useMap, $currentNamespace);
        if ($classFqn === null) {
            return null;
        }
        $classLike = $classes->find($classFqn);
        if ($classLike === null) {
            return null;
        }
        $method = self::findMethod($classLike, $call->name->toString());
        if ($method === null) {
            return null;
        }
        $params = $method->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        if (!is_array($params) || count($params) !== count($args)) {
            return null;
        }
        $paramMap = [];
        $paramNames = [];
        foreach ($params as $i => $param) {
            if (!$param instanceof TypeParam || !($args[$i] instanceof TypeRef)) {
                return null;
            }
            $paramMap[$param->name] = $args[$i];
            $paramNames[] = $param->name;
        }
        $returnType = $method->returnType;
        if ($returnType === null) {
            return null;
        }
        [$nullable, $ref] = self::returnTypeToRef($returnType, $paramNames) ?? [null, null];
        if ($ref === null) {
            return null;
        }
        $substituted = Specializer::substituteTypeRef($ref, Substitution::of($paramMap));
        return new ResolvedType($substituted, $nullable);
    }

    /**
     * Resolve a single-segment or qualified class Name through the per-
     * document use map.  Mirrors the resolution rules used by xphp's own
     * parser: leading `\` means already FQ; first segment matched against
     * the use map; otherwise prefixed with the current namespace.
     *
     * @param array<string, string> $useMap
     */
    private static function resolveNameWithUseMap(Name $name, array $useMap, string $currentNamespace): ?string
    {
        $raw = $name->toString();
        if (str_starts_with($raw, '\\')) {
            return ltrim($raw, '\\');
        }
        $first = self::firstSegment($raw);
        if (isset($useMap[$first])) {
            $rest = substr($raw, strlen($first));
            return $useMap[$first] . $rest;
        }
        return $currentNamespace !== ''
            ? $currentNamespace . '\\' . $raw
            : $raw;
    }

    private static function firstSegment(string $name): string
    {
        $pos = strpos($name, '\\');
        return $pos === false ? $name : substr($name, 0, $pos);
    }

    /**
     * Resolve a `generic_fn<TypeArgs>(...)` RHS.  The FuncCall carries
     * `ATTR_TEMPLATE_FQN` (resolved against use-fn aliases by xphp's
     * parser) and `ATTR_METHOD_GENERIC_ARGS`.  Look up the Function_
     * declaration via FqnIndex (open-doc preferred, filesystem fallback),
     * substitute the type-params in the return type.
     *
     * @internal called from the visitor closure.
     */
    public static function resolveFuncCall(FuncCall $call, FqnIndex $fqnIndex): ?ResolvedType
    {
        if (!$call->name instanceof Name) {
            return null;
        }
        $templateFqn = $call->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
        $args = $call->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        if (!is_string($templateFqn) || !is_array($args) || $args === []) {
            return null;
        }
        $function = $fqnIndex->functionFor($templateFqn);
        if ($function === null) {
            return null;
        }
        $params = $function->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        if (!is_array($params) || count($params) !== count($args)) {
            return null;
        }
        $paramMap = [];
        $paramNames = [];
        foreach ($params as $i => $param) {
            if (!$param instanceof TypeParam || !($args[$i] instanceof TypeRef)) {
                return null;
            }
            $paramMap[$param->name] = $args[$i];
            $paramNames[] = $param->name;
        }
        $returnType = $function->returnType;
        if ($returnType === null) {
            return null;
        }
        [$nullable, $ref] = self::returnTypeToRef($returnType, $paramNames) ?? [null, null];
        if ($ref === null) {
            return null;
        }
        $substituted = Specializer::substituteTypeRef($ref, Substitution::of($paramMap));
        return new ResolvedType($substituted, $nullable);
    }

    private static function findMethod(ClassLike $class, string $name): ?ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if (strcasecmp($method->name->toString(), $name) === 0) {
                return $method;
            }
        }
        return null;
    }

    /**
     * Find `$name` on the receiver's class, walking the `extends` chain when the
     * receiver's own class doesn't declare it. xphp 0.3.0 allows a generic
     * method inherited from a base class to be called with turbofish on a
     * subclass receiver (`$child->m::<int>()`); the method's generic params live
     * on the DECLARING class, so we return the `ClassMethod` together with that
     * class's context (needed to qualify its return type against the file that
     * declared it). Parent names are resolved against each class's own declaring
     * context; a `seen` set guards against extends cycles.
     *
     * @return array{0: ClassMethod, 1: ClassLikeContext}|null
     */
    private static function findMethodWithContext(
        ClassLikeContext $context,
        string $name,
        ClassLikeLookup $classes,
    ): ?array {
        $seen = [];
        $current = $context;
        while ($current !== null) {
            $method = self::findMethod($current->classLike, $name);
            if ($method !== null) {
                return [$method, $current];
            }
            $class = $current->classLike;
            if (!$class instanceof Node\Stmt\Class_ || $class->extends === null) {
                return null;
            }
            $parent = $class->extends;
            // A fully-qualified `extends \App\Base` is absolute; anything else
            // (bare `Base`, namespace-relative `Sub\Base`, aliased `F\Base`) must
            // be resolved against THIS class's own use-map + namespace, since an
            // ancestor may live in a different file/namespace than the receiver.
            $qualified = $parent instanceof Node\Name\FullyQualified
                ? ltrim($parent->toString(), '\\')
                : (self::resolveNameWithUseMap($parent, $current->useMap, $current->namespace) ?? ltrim($parent->toString(), '\\'));
            if (isset($seen[$qualified])) {
                return null;
            }
            $seen[$qualified] = true;
            $current = $classes->findWithContext($qualified);
        }
        return null;
    }

    /**
     * Convert a nikic return-type node into a (nullable, TypeRef) tuple.
     *
     * `NullableType` is unwrapped and the nullable flag is tracked
     * separately because `TypeRef` (compile-pipeline value type) doesn't
     * encode nullability -- monomorphization stamps the `?` at type-hint
     * emission, not in the ref.  Keeping it separate also lets the ref
     * pass cleanly through `Specializer::substituteTypeRef` without the
     * `?` polluting the param lookup key.
     *
     * Returns null for shapes we don't model (union, intersection).
     *
     * @param list<string> $paramNames  generic param names on the enclosing class
     * @return array{0: bool, 1: TypeRef}|null
     */
    private static function returnTypeToRef(Node $type, array $paramNames): ?array
    {
        if ($type instanceof NullableType) {
            $inner = self::returnTypeToRef($type->type, $paramNames);
            if ($inner === null) {
                return null;
            }
            return [true, $inner[1]];
        }
        if ($type instanceof Identifier) {
            return [false, new TypeRef($type->toString(), [], isScalar: true)];
        }
        if ($type instanceof Name) {
            $raw = $type->toString();
            if (in_array($raw, $paramNames, true)) {
                return [false, new TypeRef($raw, [], isTypeParam: true)];
            }
            // XphpSourceParser stamps `ATTR_TEMPLATE_FQN` on Name nodes
            // followed by `<...>` (e.g. `Collection<T>` in a return type)
            // and `ATTR_GENERIC_ARGS` with the resolved TypeRef list.
            // Both are exactly what we need: a fully-qualified class name
            // plus the args (which Specializer::substituteTypeRef will
            // recurse into to substitute nested type-params).
            $templateFqn = $type->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
            $args = $type->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
            if (is_string($templateFqn)) {
                $argList = is_array($args)
                    ? array_values(array_filter($args, static fn ($a): bool => $a instanceof TypeRef))
                    : [];
                return [false, new TypeRef($templateFqn, $argList)];
            }
            // Bare un-generic Name (e.g. `User` directly).  No way to
            // qualify without NameResolver -- hand back the raw name.
            return [false, new TypeRef($raw)];
        }
        if ($type instanceof UnionType || $type instanceof IntersectionType || $type instanceof ComplexType) {
            return null;
        }
        return null;
    }

    private function renderBinding(VarBinding $binding): string
    {
        if ($binding->paramMap === []) {
            return $binding->classFqn;
        }
        $args = array_map(
            static fn (TypeRef $r): string => $r->toDisplayString(),
            array_values($binding->paramMap),
        );
        return $binding->classFqn . '<' . implode(', ', $args) . '>';
    }
}
