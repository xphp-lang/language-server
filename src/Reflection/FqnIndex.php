<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Resolver\BoundExprView;
use XPHP\Lsp\Stderr;
use XPHP\Transpiler\Monomorphize\BoundExpr;
use XPHP\Transpiler\Monomorphize\TypeParam;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Workspace-wide FQN -> declaration index, covering BOTH open documents
 * (live via the workspace + cache) and on-disk files under rootPath
 * (lazy filesystem walk).  Single source of truth for every "where is
 * `App\Containers\Collection` declared?" query in the LSP.
 *
 * Two consumers exist today:
 *   - `FilesystemSourceLocator` (worse-reflection adapter, wants stripped
 *     source by FQN) -- thin adapter on top of pathFor() + a file_get_contents.
 *   - `FilesystemClassLikeLookup` (GenericResolver dependency, wants the
 *     parsed `ClassLike` AST with xphp attributes intact) -- thin adapter
 *     on top of parsedFor().
 *
 * Open-doc URIs win over filesystem paths when they collide on the same
 * FQN: a generic class with unsaved edits in the editor reflects the
 * editor state, not the on-disk state.  Among filesystem declarations of
 * the SAME FQN (common in monorepo / fixture trees), resolution picks the
 * one nearest the requesting document -- see {@see selectDecl} /
 * {@see nearestDecl} -- so duplicate FQNs no longer require excluding whole
 * subtrees from the walk (the prior `test/fixture` skip is gone).
 *
 * Filesystem walk lifecycle: built lazily on first call, retained for the
 * LSP session.  A file watcher (`workspace/didChangeWatchedFiles`) is the
 * follow-up (Phase 2.4 in the roadmap); for now an LSP restart picks up
 * new files added to disk after session start.
 *
 * Skipped paths in the filesystem walk:
 *   - `.git`, `vendor`, `node_modules`, `var`, `build`
 *
 * Inherited from FilesystemSourceLocator's prior behaviour; same
 * rationale (build artifacts, third-party trees, irrelevant binaries).
 */
final class FqnIndex
{
    private const SKIP_DIRS = ['.git', 'vendor', 'node_modules', 'var', 'build'];

    /**
     * @var array<string, string>|null  FQN -> absolute filesystem path; null until first build.
     */
    private ?array $filesystemMap = null;

    /**
     * @var array<string, list<array{path: string, kind: string, line: int, char: int, genericParams: list<string>, bounds: list<?string>}>>|null
     *   FQN -> EVERY filesystem declaration of that FQN, one record per
     *   declaring file. The authoritative multi-path map that powers
     *   proximity-aware resolution: when several files declare the same FQN
     *   (common in monorepo fixture trees), resolution picks the record
     *   nearest the requesting document. The legacy single-value maps above
     *   are derived views (global shortest-path winner) for origin-less
     *   consumers. Null until first build.
     */
    private ?array $filesystemDecls = null;

    /**
     * @var array<string, string>|null  FQN -> "class" or "function" kind for the filesystem entries.
     */
    private ?array $filesystemKinds = null;

    /**
     * @var array<string, array{kind: string, line: int, char: int}>|null
     *   FQN -> declaration metadata for the filesystem entries.  Carries the
     *   finer ClassLike kind (interface/trait/enum/class) + the identifier
     *   token's (line, char) so `allDeclarations()` can build a Location
     *   without re-parsing.  Open-doc declarations are walked on demand and
     *   not stored here.
     */
    private ?array $filesystemSymbols = null;

    /**
     * @var list<string>|null  Absolute paths of every .xphp/.php file the
     *   walk visited, regardless of whether the file declared any FQNs.
     *   Used by `indexedFilesystemPaths()` -- find-references needs to
     *   see files that USE classes without declaring them (Consumer.xphp
     *   does `new App\User()` but defines nothing of its own).
     */
    private ?array $filesystemWalkedPaths = null;

    /**
     * @var array<string, list<string>>|null  FQN -> ordered list of generic-param names; null until first build.
     * Populated alongside the filesystem walk so consumers like `GenericParamRegistry::prettify`
     * don't trigger N additional parses to read `ATTR_GENERIC_PARAMS` per class.
     */
    private ?array $filesystemGenericParams = null;

    /**
     * @var array<string, list<string>>|null  synthetic-FQN -> param names for
     *     function- and method-scope generics (`function identity<T>(...)`,
     *     `Util::identity<T>(...)`).  Same role as `filesystemGenericParams`
     *     but keyed under `Namespace\funcName` / `Namespace\Class::method` so
     *     it never collides with class entries.  The registry only reads the
     *     namespace prefix to build its pair set, so the leaf shape doesn't
     *     matter beyond uniqueness.
     */
    private ?array $filesystemFuncMethodGenericParams = null;

    /**
     * @var array<string, list<?string>>|null  fqn -> ordered bound FQNs per
     *     generic-param slot (null entries for unbounded params).  Phase 3
     *     bound-aware type-arg completion reads this to filter candidates
     *     against the slot's declared upper bound.
     */
    private ?array $filesystemGenericBounds = null;

    /**
     * Monotonic version counter bumped each {@see invalidateFilesystem}.
     * Downstream caches (notably the per-FQN TextDocument hit-cache in
     * {@see FilesystemSourceLocator}) consult it to know when to flush.
     */
    private int $filesystemVersion = 0;

    /**
     * Lazy-built set of `<ns>\<paramName>` strings -- every type-param
     * name namespace-prefixed by the FQN of its enclosing ClassLike's
     * namespace.  Lookup-only: callers ask "is this resolved-FQN
     * actually a type-param reference?" and skip the
     * not-a-class-but-locator-tries-anyway path.  See
     * {@see isTypeParamFqn} for the consumer.
     *
     * @var array<string, true>|null
     */
    private ?array $typeParamFqns = null;

    /**
     * The document a resolution is being performed "on behalf of", used as the
     * proximity anchor when several files declare the same FQN. Set once per
     * request by {@see \XPHP\Lsp\Dispatcher\OriginTrackingMiddleware} from the
     * request's `textDocument.uri`. The deep resolver chains and the
     * worse-reflection locator carry no document context of their own, so this
     * holder is how proximity reaches them. Null = no anchor (global tiebreak).
     */
    private ?string $currentOrigin = null;

    /** @var list<string> deduped, existing source-root dirs to walk (realpath'd). */
    private readonly array $sourceRoots;

    /** @var array<string, true> realpath'd dirs to prune from the walk (manifest output/cache). */
    private readonly array $excludedRealDirs;

    /**
     * @param string       $rootPath         the primary workspace root (InitializeParams). An
     *                                        empty string means "no filesystem walk".
     * @param list<string> $extraSourceRoots additional absolute source roots from an `xphp.json`
     *                                        manifest, walked alongside `$rootPath`. Overlapping /
     *                                        duplicate roots are deduped, and files reached through
     *                                        more than one root are indexed once.
     * @param list<string> $excludedDirs      absolute dirs to prune from the walk (the manifest's
     *                                        build-output / generated-class-cache dirs), so
     *                                        generated PHP isn't indexed as source.
     */
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly XphpSourceParser $parser,
        private readonly string $rootPath,
        array $extraSourceRoots = [],
        array $excludedDirs = [],
    ) {
        $this->sourceRoots = self::normalizeRoots($rootPath, $extraSourceRoots);
        $this->excludedRealDirs = self::normalizeExcludedDirs($excludedDirs);
    }

    /**
     * Dedup `$rootPath` + the manifest roots to the set of existing directories,
     * keyed by realpath so nested / repeated roots collapse. An empty or
     * non-directory entry is dropped (the empty-`rootPath` "no walk" sentinel).
     *
     * @param list<string> $extraSourceRoots
     * @return list<string>
     */
    private static function normalizeRoots(string $rootPath, array $extraSourceRoots): array
    {
        $seen = [];
        $roots = [];
        foreach ([$rootPath, ...$extraSourceRoots] as $candidate) {
            if ($candidate === '' || !is_dir($candidate)) {
                continue;
            }
            $real = realpath($candidate);
            $key = $real !== false ? $real : $candidate;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $roots[] = $key;
        }

        return $roots;
    }

    /**
     * @param list<string> $excludedDirs
     * @return array<string, true>
     */
    private static function normalizeExcludedDirs(array $excludedDirs): array
    {
        $out = [];
        foreach ($excludedDirs as $dir) {
            if ($dir === '') {
                continue;
            }
            $real = realpath($dir);
            if ($real !== false) {
                $out[$real] = true;
            }
        }

        return $out;
    }

    /**
     * Set the proximity anchor for subsequent resolutions (the document the
     * current request is for), or null to clear it. Cheap; called per request.
     */
    public function withOrigin(?string $uri): void
    {
        $this->currentOrigin = $uri;
    }

    public function currentOrigin(): ?string
    {
        return $this->currentOrigin;
    }

    /**
     * Path to the declaration site for `$fqn`, or null if no declaration
     * is known.  Open-doc URIs win over filesystem paths.
     */
    public function pathFor(string $fqn, ?string $origin = null): ?string
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }
        $uri = $this->openDocUriFor($needle);
        if ($uri !== null) {
            return $uri;
        }
        return $this->selectDecl($needle, $origin)['path'] ?? null;
    }

    /**
     * Locate the `Function_` AST for `$fqn` (free function, not method).
     * Open-doc declarations win.  Filesystem-only declarations parse on
     * demand via `XphpSourceParser::parseTolerant()` so the function's
     * `ATTR_METHOD_GENERIC_PARAMS` survives even for mid-edit sources.
     *
     * Used by `GenericResolver` to substitute type-args on generic-
     * function call sites (`identity<User>(...)`).  Methods reuse
     * `classLikeFor()` -> `findMethod` instead.
     */
    public function functionFor(string $fqn, ?string $origin = null): ?Function_
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }
        $hit = $this->openDocFunction($needle);
        if ($hit !== null) {
            return $hit;
        }
        $path = $this->selectDecl($needle, $origin)['path'] ?? null;
        if ($path === null) {
            return null;
        }
        return $this->functionFromFile($path, $needle);
    }

    /**
     * Locate the `ClassLike` AST for `$fqn` with xphp attributes
     * (`ATTR_GENERIC_PARAMS`, `ATTR_TEMPLATE_FQN`, ...) intact.  Open-doc
     * declarations short-circuit through the shared `ParsedDocumentCache`;
     * filesystem-only declarations parse on demand via `XphpSourceParser`
     * (tolerant) so attributes are still attached even on partially
     * malformed sources.
     */
    public function classLikeFor(string $fqn, ?string $origin = null): ?ClassLike
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }

        // Open-doc first: live view of unsaved edits beats the on-disk copy.
        $hit = $this->openDocClassLike($needle);
        if ($hit !== null) {
            return $hit;
        }

        $path = $this->selectDecl($needle, $origin)['path'] ?? null;
        if ($path === null) {
            return null;
        }
        return $this->classLikeFromFile($path, $needle);
    }

    /**
     * Like {@see classLikeFor} but also return the declaring file's full AST,
     * so a caller can derive the file's name-resolution context (use-map +
     * namespace). Both branches reuse {@see ParsedDocumentCache} so a deep
     * chain doesn't re-parse the declaring file per hop.
     *
     * @return array{classLike: ClassLike, ast: list<\PhpParser\Node\Stmt>}|null
     */
    public function classLikeAstFor(string $fqn, ?string $origin = null): ?array
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }

        // Open-doc first (live view of unsaved edits).
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $hit = self::findClassLikeInAst($result->ast, $needle);
            if ($hit !== null) {
                return ['classLike' => $hit, 'ast' => $result->ast];
            }
        }

        $path = $this->selectDecl($needle, $origin)['path'] ?? null;
        if ($path === null) {
            return null;
        }
        $source = @file_get_contents($path);
        if ($source === false) {
            return null;
        }
        // version -1 = "filesystem snapshot" (mirrors boundExprsForGenericClass);
        // routes through the cache so repeated chain hops don't re-parse.
        $result = $this->cache->getOrParse('file://' . $path, -1, $source);
        if ($result->ast === null) {
            return null;
        }
        $hit = self::findClassLikeInAst($result->ast, $needle);
        return $hit === null ? null : ['classLike' => $hit, 'ast' => $result->ast];
    }

    /**
     * Every class/interface/trait FQN known to the index, from both open
     * docs and the filesystem.  De-duplicated; ordering is insertion-stable
     * (open docs first, then filesystem in walk order).
     *
     * @return list<string>
     */
    public function allClassFqns(): array
    {
        $fqns = [];
        foreach ($this->openDocClassFqns() as $fqn) {
            $fqns[$fqn] = true;
        }
        foreach ($this->filesystemKinds() as $fqn => $kind) {
            if ($kind === 'class') {
                $fqns[$fqn] = true;
            }
        }
        return array_keys($fqns);
    }

    /**
     * Yield every generic ClassLike's `(fqn, paramNames)` pair across both
     * sources.  Used by `GenericParamRegistry` to populate its
     * placeholder lookup without doing its own workspace walk.
     *
     * Open-doc declarations are walked through `ParsedDocumentCache` so
     * unsaved edits reflect immediately; filesystem-only declarations
     * come from the pre-built generic-params map populated during
     * `buildFilesystemIndex` (no extra parses).
     *
     * Open-doc wins on FQN collisions: a class declared both on disk and
     * in an open buffer yields once with the open-doc's param list.
     *
     * @return iterable<string, list<string>>  yields `fqn => paramNames`
     */
    public function iterGenericClasses(): iterable
    {
        $seen = [];
        // Open docs first so their data wins on collision.
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectGenericClasses($result->ast) as $fqn => $paramNames) {
                if (isset($seen[$fqn])) {
                    continue;
                }
                $seen[$fqn] = true;
                yield $fqn => $paramNames;
            }
        }
        foreach ($this->filesystemGenericParams() as $fqn => $paramNames) {
            if (isset($seen[$fqn])) {
                continue;
            }
            $seen[$fqn] = true;
            yield $fqn => $paramNames;
        }
    }

    /**
     * Like `iterGenericClasses`, but yields function- and method-scope
     * generic placeholders -- the ones declared with
     * `ATTR_METHOD_GENERIC_PARAMS` on `Function_` and `ClassMethod` nodes.
     *
     * Same shape (`syntheticFqn => paramNames`) so `GenericParamRegistry`
     * can iterate without caring about the source: it derives
     * `(namespace, paramName)` from the FQN's last-backslash split, and a
     * synthetic key like `App\Demos\identity` or `App\Containers\Util::identity`
     * yields the right namespace either way.
     *
     * Why this is separate from `iterGenericClasses`: class generics carry
     * `ATTR_GENERIC_PARAMS`; function/method generics carry the distinct
     * `ATTR_METHOD_GENERIC_PARAMS`.  Different attributes, different walks --
     * keeping them as sibling iterators avoids overloading a single
     * collector with two unrelated AST shapes.
     *
     * @return iterable<string, list<string>>
     */
    public function iterGenericFunctionsAndMethods(): iterable
    {
        $seen = [];
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectGenericFunctionsAndMethods($result->ast) as $fqn => $paramNames) {
                if (isset($seen[$fqn])) {
                    continue;
                }
                $seen[$fqn] = true;
                yield $fqn => $paramNames;
            }
        }
        foreach ($this->filesystemFuncMethodGenericParams() as $fqn => $paramNames) {
            if (isset($seen[$fqn])) {
                continue;
            }
            $seen[$fqn] = true;
            yield $fqn => $paramNames;
        }
    }

    /**
     * Discard the cached filesystem index so the next query rebuilds it
     * from a fresh walk under rootPath.  Open-doc state is unaffected
     * (it's already version-keyed through `ParsedDocumentCache`).
     *
     * Called by the file-watcher handler (Phase 2.4) on
     * `workspace/didChangeWatchedFiles` events -- bulk invalidation is
     * cheaper than surgical per-file updates given how fast the walk is
     * (~100ms across the playground), and the next FqnIndex query is
     * usually one keystroke away anyway.
     */
    public function invalidateFilesystem(): void
    {
        $this->filesystemMap = null;
        $this->filesystemDecls = null;
        $this->filesystemKinds = null;
        $this->filesystemGenericParams = null;
        $this->filesystemFuncMethodGenericParams = null;
        $this->filesystemGenericBounds = null;
        $this->filesystemSymbols = null;
        $this->filesystemWalkedPaths = null;
        $this->typeParamFqns = null;
        $this->filesystemVersion++;
    }

    /**
     * Monotonically-increasing counter bumped on every
     * {@see invalidateFilesystem} call.  Downstream caches (notably
     * {@see FilesystemSourceLocator}'s per-FQN TextDocument cache) read
     * this to know when to drop their own memoized state.
     */
    public function filesystemVersion(): int
    {
        return $this->filesystemVersion;
    }

    /**
     * Is `$fqn` a namespace-resolved type-parameter reference rather
     * than a real class FQN?
     *
     * When source code inside `namespace App\Containers` references a
     * type-param `T`, nikic's name resolver attaches
     * `App\Containers\T` as the namespacedName.  Worse-reflection then
     * asks our `SourceCodeLocator` chain "where is `App\Containers\T`?",
     * which misses (because `T` is a type-param, not a class) and
     * wastes a workspace walk per lookup.
     *
     * This check answers cheaply: "is the LAST segment of $fqn a
     * type-param of any generic class declared in the SAME namespace?"
     * If yes, the locator can short-circuit immediately -- no log,
     * no walk, still throws SourceNotFound to keep worse-reflection's
     * chain falling through.
     *
     * Lookup is O(1) once the lazy set is built;
     * {@see typeParamFqns} populates it from
     * {@see iterGenericClasses} on first call.
     */
    public function isTypeParamFqn(string $fqn): bool
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return false;
        }
        return isset($this->typeParamFqns()[$needle]);
    }

    /**
     * Is `$fqn` a namespace-resolved reference to a global PHP function
     * rather than a class FQN?
     *
     * Fix 3 (extends Fix L's silent-bail pattern): when source code
     * inside `namespace App\Demos` calls `gettype($x)`, nikic's name
     * resolver speculatively emits `App\Demos\gettype` as the
     * namespacedName -- PHP's actual function-lookup falls back to
     * the global namespace at runtime, but the static AST view shows
     * the prefixed form first.  Worse-reflection then asks our
     * locator chain "where is `App\Demos\gettype`?", which misses and
     * writes a `[xphp-lsp locator] miss …` line to stderr.
     *
     * This predicate recognises that shape: an FQN with a non-empty
     * namespace whose last segment is the name of a PHP-internal
     * function (case-insensitive, per PHP function semantics).  The
     * locator uses it to suppress the miss log while still throwing
     * SourceNotFound -- worse-reflection's chain still falls through
     * normally; only the stderr noise goes away.
     *
     * Cross-checked against `ReflectionFunction::isInternal()` so a
     * user-defined function happening to be loaded into the LSP
     * server's process doesn't accidentally suppress a legitimate
     * class-name lookup.
     */
    public function isBareBuiltinFunctionFqn(string $fqn): bool
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return false;
        }
        $lastBackslash = strrpos($needle, '\\');
        if ($lastBackslash === false) {
            // Global-namespace lookup -- can't tell apart from a
            // legitimate global-class reference to a class named after
            // a function.  Conservative: don't claim it.
            return false;
        }
        $shortName = substr($needle, $lastBackslash + 1);
        if ($shortName === '' || !function_exists($shortName)) {
            return false;
        }
        try {
            return (new \ReflectionFunction($shortName))->isInternal();
        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
     * @return array<string, true>
     */
    private function typeParamFqns(): array
    {
        if ($this->typeParamFqns !== null) {
            return $this->typeParamFqns;
        }
        $set = [];
        foreach ($this->iterGenericClasses() as $classFqn => $paramNames) {
            $namespace = self::namespaceOf($classFqn);
            foreach ($paramNames as $paramName) {
                $key = $namespace === '' ? $paramName : $namespace . '\\' . $paramName;
                $set[$key] = true;
            }
        }
        // Function- and method-scope generics share the problem: a `T`
        // inside `function App\Demos\identity<T>(...)` becomes
        // `App\Demos\T` after name resolution, and inside
        // `class App\Containers\Util { function id<T>(...) }` the
        // synthetic key splits at the last `\` so namespace =
        // `App\Containers`, which matches what name resolution emits
        // for a bare `T` inside that method body.
        foreach ($this->iterGenericFunctionsAndMethods() as $scopeFqn => $paramNames) {
            $namespace = self::namespaceOf($scopeFqn);
            foreach ($paramNames as $paramName) {
                $key = $namespace === '' ? $paramName : $namespace . '\\' . $paramName;
                $set[$key] = true;
            }
        }
        return $this->typeParamFqns = $set;
    }

    private static function namespaceOf(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? '' : substr($fqn, 0, $pos);
    }

    /**
     * Look up the bound FQN list for a generic class.  Each entry is the
     * declared upper bound for that slot (e.g. `Stringable` for
     * `Box<T: Stringable>`), or `null` when the slot is unbounded.
     *
     * Open-doc declarations win over filesystem copies, matching the rest
     * of the index.  Returns `null` when no generic-class declaration is
     * known for `$fqn` (non-generic class, unknown FQN, or parse failure).
     *
     * @return list<?string>|null
     */
    public function boundsForGenericClass(string $fqn, ?string $origin = null): ?array
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectGenericClassBounds($result->ast) as $boundFqn => $bounds) {
                if ($boundFqn === $needle) {
                    return $bounds;
                }
            }
        }
        $decl = $this->selectDecl($needle, $origin);
        // Empty bounds == not a generic class (the per-param bound list always
        // has >=1 slot for a real generic); keep the null contract for those.
        return ($decl === null || $decl['bounds'] === []) ? null : $decl['bounds'];
    }

    /**
     * Look up the full `BoundExpr` tree per slot for a generic class -- the
     * composite-bound counterpart of `boundsForGenericClass`. Each entry is the
     * slot's bound expression (leaf / intersection / union / F-bounded) or
     * `null` when the slot is unbounded.
     *
     * Open-doc declarations win over filesystem copies. For a filesystem-only
     * generic class the declaring file is re-parsed on demand, since the bound
     * tree (unlike a single FQN) isn't cached in the lightweight filesystem
     * index. Returns `null` when no generic-class declaration is known.
     *
     * @return list<?BoundExpr>|null
     */
    public function boundExprsForGenericClass(string $fqn, ?string $origin = null): ?array
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectGenericClassBoundExprs($result->ast) as $boundFqn => $exprs) {
                if ($boundFqn === $needle) {
                    return $exprs;
                }
            }
        }
        // Filesystem fallback: re-parse the declaring file for the bound tree.
        $decl = $this->selectDecl($needle, $origin);
        if ($decl === null) {
            return null;
        }
        $source = @file_get_contents($decl['path']);
        if ($source === false) {
            return null;
        }
        $result = $this->cache->getOrParse('file://' . $decl['path'], -1, $source);
        if ($result->ast === null) {
            return null;
        }
        foreach (self::collectGenericClassBoundExprs($result->ast) as $boundFqn => $exprs) {
            if ($boundFqn === $needle) {
                return $exprs;
            }
        }
        return null;
    }

    /**
     * @return array<string, list<?string>>
     */
    private function filesystemGenericBoundsMap(): array
    {
        if ($this->filesystemGenericBounds === null) {
            $this->buildFilesystemIndex();
        }
        return $this->filesystemGenericBounds ?? [];
    }

    /**
     * Yield every declaration the index knows about, from both open docs and
     * the filesystem.  Used by `workspace/symbol` to filter and emit
     * SymbolInformation across the workspace without each handler doing its
     * own walk.
     *
     * Each entry carries:
     *   - `fqn`     full backslash-qualified name (no leading `\`)
     *   - `kind`    "class" | "interface" | "trait" | "enum" | "function"
     *   - `uri`     file URI (open docs return the workspace URI;
     *               filesystem-only files return `file://` + absolute path)
     *   - `line`    LSP-coords line (0-based) of the identifier token
     *   - `char`    LSP-coords character offset of the identifier token
     *
     * Open-doc URIs win over filesystem paths when the same FQN is declared
     * in both -- the editor view always reflects unsaved edits.
     *
     * @return iterable<array{fqn: string, kind: string, uri: string, line: int, char: int}>
     */
    public function allDeclarations(): iterable
    {
        $seen = [];
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $offsets = $result->byteOffsetMap;
            foreach (self::collectSymbolHits($result->ast) as $hit) {
                if (isset($seen[$hit['fqn']])) {
                    continue;
                }
                $seen[$hit['fqn']] = true;
                $origByte = $offsets->toOriginal($hit['startByte']);
                [$line, $char] = self::byteToLineChar($item->text, $origByte);
                yield [
                    'fqn' => $hit['fqn'],
                    'kind' => $hit['kind'],
                    'uri' => (string) $uri,
                    'line' => $line,
                    'char' => $char,
                ];
            }
        }
        foreach ($this->filesystemSymbols() as $fqn => $meta) {
            if (isset($seen[$fqn])) {
                continue;
            }
            $seen[$fqn] = true;
            yield [
                'fqn' => $fqn,
                'kind' => $meta['kind'],
                'uri' => 'file://' . ($this->filesystemMap()[$fqn] ?? ''),
                'line' => $meta['line'],
                'char' => $meta['char'],
            ];
        }
    }

    /**
     * Locate the declaration of `$fqn` and return its identifier-token
     * position.  Open-doc declarations win; filesystem-only declarations
     * fall through using the pre-built filesystem-symbols map.  Returns
     * null when no declaration is known.
     *
     * Used by the definition handler's Path 1 (generic-instantiation
     * Name with ATTR_TEMPLATE_FQN) so GTD on `new Box<...>()` jumps to
     * the `Box` template whether it's open or on disk.
     *
     * @return array{uri: string, line: int, char: int, short: string}|null
     */
    public function locationForFqn(string $fqn, ?string $origin = null): ?array
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $offsets = $result->byteOffsetMap;
            foreach (self::collectSymbolHits($result->ast) as $hit) {
                if ($hit['fqn'] !== $needle) {
                    continue;
                }
                $origByte = $offsets->toOriginal($hit['startByte']);
                [$line, $char] = self::byteToLineChar($item->text, $origByte);
                return [
                    'uri' => (string) $uri,
                    'line' => $line,
                    'char' => $char,
                    'short' => self::shortOf($hit['fqn']),
                ];
            }
        }
        $decl = $this->selectDecl($needle, $origin);
        if ($decl === null) {
            return null;
        }
        return [
            'uri' => 'file://' . $decl['path'],
            'line' => $decl['line'],
            'char' => $decl['char'],
            'short' => self::shortOf($needle),
        ];
    }

    /**
     * Locate a method declaration's name span by (class FQN, method name).
     * Mirrors {@see locationForFqn} but resolves a member: finds the class's
     * ClassLike (open doc first, then filesystem), the ClassMethod by name, and
     * maps its name-node offset through the document's ByteOffsetMap. This is the
     * xphp-native path go-to-definition uses for generic method calls -- the
     * receiver class (e.g. `Collection<User>::first`) carries xphp syntax
     * (`T[]`, reified `T`) that worse-reflection can't reliably reflect.
     *
     * Returns null when the class isn't found or declares no such method
     * directly (inherited methods are not yet resolved).
     *
     * @return array{uri: string, line: int, char: int, short: string}|null
     */
    public function methodLocation(string $classFqn, string $methodName, ?string $origin = null): ?array
    {
        $needle = ltrim($classFqn, '\\');
        if ($needle === '' || $methodName === '') {
            return null;
        }

        // Open-doc first: live view of unsaved edits beats the on-disk copy.
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $classLike = self::findClassLikeInAst($result->ast, $needle);
            if ($classLike === null) {
                continue;
            }
            $startByte = self::methodNameStartByte($classLike, $methodName);
            if ($startByte === null) {
                return null;
            }
            $origByte = $result->byteOffsetMap->toOriginal($startByte);
            [$line, $char] = self::byteToLineChar($item->text, $origByte);
            return ['uri' => (string) $uri, 'line' => $line, 'char' => $char, 'short' => $methodName];
        }

        $path = $this->selectDecl($needle, $origin)['path'] ?? null;
        if ($path === null) {
            return null;
        }
        $source = @file_get_contents($path);
        if ($source === false) {
            return null;
        }
        try {
            $parsed = $this->parser->parseTolerantWithMap($source);
        } catch (Throwable) {
            return null;
        }
        if ($parsed === null || $parsed->ast === null) {
            return null;
        }
        $classLike = self::findClassLikeInAst($parsed->ast, $needle);
        if ($classLike === null) {
            return null;
        }
        $startByte = self::methodNameStartByte($classLike, $methodName);
        if ($startByte === null) {
            return null;
        }
        $origByte = $parsed->byteOffsetMap->toOriginal($startByte);
        [$line, $char] = self::byteToLineChar($source, $origByte);
        return ['uri' => 'file://' . $path, 'line' => $line, 'char' => $char, 'short' => $methodName];
    }

    private static function methodNameStartByte(ClassLike $classLike, string $methodName): ?int
    {
        foreach ($classLike->getMethods() as $method) {
            if (strcasecmp($method->name->toString(), $methodName) === 0) {
                $start = $method->name->getStartFilePos();
                return $start >= 0 ? $start : null;
            }
        }
        return null;
    }

    /**
     * Locate ANY declaration whose short name matches `$shortName`.  Used
     * by the definition handler's Path 2 (type-arg identifier inside a
     * `<...>` clause -- the `User` of `identity<User>(...)`) which the
     * parser strips before the post-strip parser ever sees it, so we only
     * have the short identifier source-text and need to resolve via
     * workspace + filesystem lookup.
     *
     * Resolution order (Phase 3 polish):
     *   1. Open-doc match wins.  The editor's unsaved buffer beats any
     *      on-disk copy, full stop.
     *   2. Among filesystem matches, shortest URI wins -- proxy for
     *      "closer to rootPath" since fs-walk emits absolute paths.
     *      Alphabetical tiebreak after length for determinism.
     *
     * @return array{uri: string, line: int, char: int, short: string}|null
     */
    /**
     * Return every class-like (or function) FQN whose trailing segment
     * matches `$shortName`.  Used by the import-class code action
     * (Cycle B) which surfaces one quick-fix per candidate so the user
     * can disambiguate when the same short name exists in multiple
     * namespaces.
     *
     * Result is sorted ascending by FQN length, then alphabetically --
     * shorter / closer-to-root namespaces appear first in the
     * lightbulb menu.
     *
     * @return list<string>
     */
    public function fqnsByShortName(string $shortName): array
    {
        if ($shortName === '') {
            return [];
        }
        $tailSuffix = '\\' . $shortName;
        $tailLen = strlen($tailSuffix);
        $matches = [];
        foreach ($this->allDeclarations() as $hit) {
            $fqn = $hit['fqn'];
            if ($fqn === $shortName
                || (strlen($fqn) > $tailLen && substr($fqn, -$tailLen) === $tailSuffix)
            ) {
                $matches[$fqn] = true;
            }
        }
        $sorted = array_keys($matches);
        usort($sorted, static function (string $a, string $b): int {
            $byLength = strlen($a) <=> strlen($b);
            return $byLength !== 0 ? $byLength : strcmp($a, $b);
        });
        return $sorted;
    }

    public function locationByShortName(string $shortName, ?string $origin = null): ?array
    {
        if ($shortName === '') {
            return null;
        }
        $tailSuffix = '\\' . $shortName;
        $tailLen = strlen($tailSuffix);

        // Open-doc wins outright. allDeclarations yields open docs first, then
        // filesystem ('file://' prefix); stop scanning once we reach the
        // filesystem section (open docs are exhausted).
        foreach ($this->allDeclarations() as $hit) {
            if (str_starts_with($hit['uri'], 'file://')) {
                break;
            }
            $fqn = $hit['fqn'];
            if ($fqn === $shortName
                || (strlen($fqn) > $tailLen && substr($fqn, -$tailLen) === $tailSuffix)
            ) {
                return [
                    'uri' => $hit['uri'],
                    'line' => $hit['line'],
                    'char' => $hit['char'],
                    'short' => $shortName,
                ];
            }
        }

        // Filesystem candidates: EVERY declaring path across matching FQNs,
        // so a duplicated short name resolves to the declaration nearest the
        // requesting document (or shortest path when origin is null).
        $candidates = [];
        foreach ($this->filesystemDeclsMap() as $fqn => $records) {
            if ($fqn === $shortName
                || (strlen($fqn) > $tailLen && substr($fqn, -$tailLen) === $tailSuffix)
            ) {
                foreach ($records as $record) {
                    $candidates[] = $record;
                }
            }
        }
        $best = self::nearestDecl($candidates, $origin);
        if ($best === null) {
            return null;
        }
        return [
            'uri' => 'file://' . $best['path'],
            'line' => $best['line'],
            'char' => $best['char'],
            'short' => $shortName,
        ];
    }

    /**
     * Absolute paths of every .xphp/.php file the filesystem walk visited,
     * INCLUDING files that didn't declare any FQNs (consumer-only files
     * that reference classes without defining one).  Open-doc URIs are
     * NOT included -- callers needing both sources iterate `$workspace`
     * separately first, then walk these paths skipping any already
     * covered.
     *
     * Used by the find-references finder (Phase 4.1) to enumerate the
     * cross-workspace search space without each consumer doing its own
     * walk.
     *
     * @return list<string>
     */
    public function indexedFilesystemPaths(): array
    {
        if ($this->filesystemWalkedPaths === null) {
            $this->buildFilesystemIndex();
        }
        return $this->filesystemWalkedPaths ?? [];
    }

    /**
     * Every top-level function FQN known to the index, both sources.
     *
     * @return list<string>
     */
    public function allFunctionFqns(): array
    {
        $fqns = [];
        foreach ($this->openDocFunctionFqns() as $fqn) {
            $fqns[$fqn] = true;
        }
        foreach ($this->filesystemKinds() as $fqn => $kind) {
            if ($kind === 'function') {
                $fqns[$fqn] = true;
            }
        }
        return array_keys($fqns);
    }

    // -- open-doc side (cheap; re-walked per call, ParsedDocumentCache memoizes) -------

    private function openDocUriFor(string $fqn): ?string
    {
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectDeclarations($result->ast) as $declared => $_kind) {
                if ($declared === $fqn) {
                    return (string) $uri;
                }
            }
        }
        return null;
    }

    private function openDocFunction(string $fqn): ?Function_
    {
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $hit = self::findFunctionInAst($result->ast, $fqn);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    private function functionFromFile(string $path, string $needle): ?Function_
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            return null;
        }
        try {
            $ast = $this->parser->parseTolerant($source);
        } catch (\Throwable) {
            return null;
        }
        if ($ast === null) {
            return null;
        }
        return self::findFunctionInAst($ast, $needle);
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    private static function findFunctionInAst(array $ast, string $needle): ?Function_
    {
        $visitor = new class($needle) extends NodeVisitorAbstract {
            public ?Function_ $found = null;
            private string $currentNamespace = '';

            public function __construct(private readonly string $needle)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->found !== null) {
                    return null;
                }
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                if (!$node instanceof Function_) {
                    return null;
                }
                $short = $node->name->toString();
                $fqn = $this->currentNamespace !== ''
                    ? $this->currentNamespace . '\\' . $short
                    : $short;
                if ($fqn === $this->needle) {
                    $this->found = $node;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->found;
    }

    private function openDocClassLike(string $fqn): ?ClassLike
    {
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $hit = self::findClassLikeInAst($result->ast, $fqn);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private function openDocClassFqns(): array
    {
        $fqns = [];
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectDeclarations($result->ast) as $fqn => $kind) {
                if ($kind === 'class') {
                    $fqns[$fqn] = true;
                }
            }
        }
        return array_keys($fqns);
    }

    /**
     * @return list<string>
     */
    private function openDocFunctionFqns(): array
    {
        $fqns = [];
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse($uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            foreach (self::collectDeclarations($result->ast) as $fqn => $kind) {
                if ($kind === 'function') {
                    $fqns[$fqn] = true;
                }
            }
        }
        return array_keys($fqns);
    }

    // -- filesystem side (lazy, walked once per LSP session) ---------------------------

    /**
     * @return array<string, string>  FQN -> path
     */
    private function filesystemMap(): array
    {
        if ($this->filesystemMap === null) {
            $this->buildFilesystemIndex();
        }
        return $this->filesystemMap ?? [];
    }

    /**
     * @return array<string, list<array{path: string, kind: string, line: int, char: int, genericParams: list<string>, bounds: list<?string>}>>
     */
    private function filesystemDeclsMap(): array
    {
        if ($this->filesystemDecls === null) {
            $this->buildFilesystemIndex();
        }
        return $this->filesystemDecls ?? [];
    }

    /**
     * Pick the filesystem declaration of `$fqn` nearest the requesting
     * document. When `$origin` is null (no requesting context), this reduces
     * to the global tiebreak (shortest path, then alphabetical) -- preserving
     * the index's pre-proximity behavior for origin-less callers.
     *
     * @return array{path: string, kind: string, line: int, char: int, genericParams: list<string>, bounds: list<?string>}|null
     */
    private function selectDecl(string $fqn, ?string $origin): ?array
    {
        // An explicit origin (passed by a caller that has it) wins; otherwise
        // fall back to the per-request anchor set by the middleware.
        $effective = $origin ?? $this->currentOrigin;
        return self::nearestDecl($this->filesystemDeclsMap()[$fqn] ?? [], $effective);
    }

    /**
     * Among candidate declaration records, return the one nearest `$origin`
     * (the requesting document URI/path). Proximity = number of leading path
     * components shared; ties (and a null origin) fall back to shortest path,
     * then alphabetical, for deterministic resolution.
     *
     * @param list<array{path: string, kind: string, line: int, char: int, genericParams: list<string>, bounds: list<?string>}> $records
     * @return array{path: string, kind: string, line: int, char: int, genericParams: list<string>, bounds: list<?string>}|null
     */
    private static function nearestDecl(array $records, ?string $origin): ?array
    {
        if ($records === []) {
            return null;
        }
        $originPath = $origin === null ? null : self::stripScheme($origin);
        $best = null;
        $bestShared = -1;
        foreach ($records as $record) {
            $shared = $originPath === null ? 0 : self::sharedPrefixComponents($originPath, $record['path']);
            if ($best === null
                || $shared > $bestShared
                || ($shared === $bestShared && self::pathTiebreak($record['path'], $best['path']) < 0)
            ) {
                $best = $record;
                $bestShared = $shared;
            }
        }
        return $best;
    }

    /**
     * Count of leading directory components two absolute paths share. Higher
     * means "in the same subtree" -- the proximity signal for resolution.
     */
    private static function sharedPrefixComponents(string $a, string $b): int
    {
        $aParts = explode('/', $a);
        $bParts = explode('/', $b);
        // Compare directory components only (drop the filename on each side).
        array_pop($aParts);
        array_pop($bParts);
        $n = min(count($aParts), count($bParts));
        $shared = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($aParts[$i] !== $bParts[$i]) {
                break;
            }
            $shared++;
        }
        return $shared;
    }

    /** Deterministic tiebreak: shorter path first, then alphabetical. */
    private static function pathTiebreak(string $a, string $b): int
    {
        $byLength = strlen($a) <=> strlen($b);
        return $byLength !== 0 ? $byLength : strcmp($a, $b);
    }

    private static function stripScheme(string $uri): string
    {
        return str_starts_with($uri, 'file://') ? substr($uri, strlen('file://')) : $uri;
    }

    /**
     * @return array<string, string>  FQN -> "class" or "function"
     */
    private function filesystemKinds(): array
    {
        if ($this->filesystemKinds === null) {
            $this->buildFilesystemIndex();
        }
        return $this->filesystemKinds ?? [];
    }

    /**
     * @return array<string, list<string>>  FQN -> ordered generic param names
     */
    private function filesystemGenericParams(): array
    {
        if ($this->filesystemGenericParams === null) {
            $this->buildFilesystemIndex();
        }
        return $this->filesystemGenericParams ?? [];
    }

    /**
     * @return array<string, list<string>>  synthetic-FQN -> ordered param names
     */
    private function filesystemFuncMethodGenericParams(): array
    {
        if ($this->filesystemFuncMethodGenericParams === null) {
            $this->buildFilesystemIndex();
        }
        return $this->filesystemFuncMethodGenericParams ?? [];
    }

    /**
     * @return array<string, array{kind: string, line: int, char: int}>
     */
    private function filesystemSymbols(): array
    {
        if ($this->filesystemSymbols === null) {
            $this->buildFilesystemIndex();
        }
        return $this->filesystemSymbols ?? [];
    }

    private function buildFilesystemIndex(): void
    {
        $map = [];
        $decls = [];
        $kinds = [];
        $genericParams = [];
        $funcMethodGenericParams = [];
        $genericBounds = [];
        $symbols = [];
        $walkedPaths = [];
        if ($this->sourceRoots === []) {
            Stderr::write(sprintf(
                "[xphp-lsp fqn-index] no source roots (rootPath %s); filesystem index empty\n",
                $this->rootPath === '' ? '<empty>' : $this->rootPath,
            ));
            $this->filesystemMap = $map;
            $this->filesystemDecls = $decls;
            $this->filesystemKinds = $kinds;
            $this->filesystemGenericParams = $genericParams;
            $this->filesystemFuncMethodGenericParams = $funcMethodGenericParams;
            $this->filesystemGenericBounds = $genericBounds;
            $this->filesystemSymbols = $symbols;
            $this->filesystemWalkedPaths = $walkedPaths;
            return;
        }

        $filesScanned = 0;
        $seenFiles = [];
        foreach ($this->sourceRoots as $root) {
        foreach ($this->iterator($root) as $file) {
            /** @var SplFileInfo $file */
            $ext = $file->getExtension();
            if ($ext !== 'php' && $ext !== 'xphp') {
                continue;
            }
            // A file reachable through more than one (nested / overlapping) root
            // must be indexed once -- `$decls` appends, so a second visit would
            // duplicate every declaration record.
            $pathname = $file->getPathname();
            $realFile = realpath($pathname);
            $seenKey = $realFile !== false ? $realFile : $pathname;
            if (isset($seenFiles[$seenKey])) {
                continue;
            }
            $seenFiles[$seenKey] = true;
            $filesScanned++;
            $walkedPaths[] = $pathname;

            $source = @file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }

            try {
                $parsed = $this->parser->parseTolerantWithMap($source);
            } catch (Throwable) {
                continue;
            }
            if ($parsed === null) {
                continue;
            }
            $ast = $parsed->ast;
            $offsets = $parsed->byteOffsetMap;

            // Per-file declaration records, joined by FQN across the
            // individual collector walks. Seeded from collectDeclarations
            // (classes + free functions) and enriched with the finer symbol
            // kind/position + generic params/bounds where available.
            $fileDecls = [];
            foreach (self::collectDeclarations($ast) as $fqn => $kind) {
                $map[$fqn] = $file->getPathname();
                $kinds[$fqn] = $kind;
                $fileDecls[$fqn] = [
                    'path' => $file->getPathname(),
                    'kind' => $kind,
                    'line' => 0,
                    'char' => 0,
                    'genericParams' => [],
                    'bounds' => [],
                ];
            }
            foreach (self::collectGenericClasses($ast) as $fqn => $paramNames) {
                $genericParams[$fqn] = $paramNames;
                if (isset($fileDecls[$fqn])) {
                    $fileDecls[$fqn]['genericParams'] = $paramNames;
                }
            }
            foreach (self::collectGenericFunctionsAndMethods($ast) as $fqn => $paramNames) {
                $funcMethodGenericParams[$fqn] = $paramNames;
            }
            foreach (self::collectGenericClassBounds($ast) as $fqn => $bounds) {
                $genericBounds[$fqn] = $bounds;
                if (isset($fileDecls[$fqn])) {
                    $fileDecls[$fqn]['bounds'] = $bounds;
                }
            }
            foreach (self::collectSymbolHits($ast) as $hit) {
                $origByte = $offsets->toOriginal($hit['startByte']);
                [$line, $char] = self::byteToLineChar($source, $origByte);
                $symbols[$hit['fqn']] = [
                    'kind' => $hit['kind'],
                    'line' => $line,
                    'char' => $char,
                ];
                if (isset($fileDecls[$hit['fqn']])) {
                    $fileDecls[$hit['fqn']]['kind'] = $hit['kind'];
                    $fileDecls[$hit['fqn']]['line'] = $line;
                    $fileDecls[$hit['fqn']]['char'] = $char;
                }
            }
            foreach ($fileDecls as $fqn => $record) {
                $decls[$fqn][] = $record;
            }
        }
        }

        Stderr::write(sprintf(
            "[xphp-lsp fqn-index] indexed %d FQNs from %d files under %s (skipped: %s)\n",
            count($map),
            $filesScanned,
            implode(', ', $this->sourceRoots),
            implode(', ', self::SKIP_DIRS),
        ));

        $this->filesystemMap = $map;
        $this->filesystemDecls = $decls;
        $this->filesystemKinds = $kinds;
        $this->filesystemGenericParams = $genericParams;
        $this->filesystemFuncMethodGenericParams = $funcMethodGenericParams;
        $this->filesystemGenericBounds = $genericBounds;
        $this->filesystemSymbols = $symbols;
        $this->filesystemWalkedPaths = $walkedPaths;
    }

    private function classLikeFromFile(string $path, string $needle): ?ClassLike
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            return null;
        }
        try {
            $ast = $this->parser->parseTolerant($source);
        } catch (Throwable) {
            return null;
        }
        if ($ast === null) {
            return null;
        }
        return self::findClassLikeInAst($ast, $needle);
    }

    private function iterator(string $root): RecursiveIteratorIterator
    {
        $directoryIterator = new RecursiveDirectoryIterator(
            $root,
            RecursiveDirectoryIterator::SKIP_DOTS,
        );

        $excluded = $this->excludedRealDirs;
        $filter = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function (SplFileInfo $file) use ($excluded): bool {
                if (!$file->isDir()) {
                    return true;
                }
                $name = $file->getFilename();
                if (in_array($name, self::SKIP_DIRS, true)) {
                    return false;
                }
                // Prune the manifest's build-output / cache dirs (which may sit
                // anywhere under a root and carry names outside SKIP_DIRS) so
                // generated PHP isn't indexed as source.
                if ($excluded !== []) {
                    $real = realpath($file->getPathname());
                    if ($real !== false && isset($excluded[$real])) {
                        return false;
                    }
                }
                return true;
            },
        );

        return new RecursiveIteratorIterator($filter);
    }

    // -- AST helpers -------------------------------------------------------------------

    /**
     * Walk an AST collecting `FQN => "class"|"function"` for every ClassLike
     * and top-level Function_ declaration.  Methods and closures don't
     * count -- only namespace-level functions.
     *
     * @param list<Node\Stmt> $ast
     * @return array<string, string>
     */
    private static function collectDeclarations(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, string> */
            public array $fqns = [];

            private string $currentNamespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                $short = null;
                $kind = null;
                if ($node instanceof ClassLike && $node->name !== null) {
                    $short = $node->name->toString();
                    $kind = 'class';
                } elseif ($node instanceof Function_) {
                    $short = $node->name->toString();
                    $kind = 'function';
                }
                if ($short !== null) {
                    $fqn = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $short
                        : $short;
                    $this->fqns[$fqn] = $kind;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->fqns;
    }

    /**
     * Walk an AST collecting one entry per ClassLike / Function_ declaration
     * with the finer ClassLike kind preserved (interface / trait / enum /
     * class) and the identifier token's start byte (stripped-source coords --
     * caller back-translates through the byte-offset map before mapping to
     * line/character).
     *
     * Used by `allDeclarations()` to power workspace/symbol search; the
     * coarser `collectDeclarations()` path is kept for the existing FQN-only
     * consumers.
     *
     * @param list<Node\Stmt> $ast
     * @return list<array{fqn: string, kind: string, startByte: int}>
     */
    private static function collectSymbolHits(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<array{fqn: string, kind: string, startByte: int}> */
            public array $hits = [];

            private string $currentNamespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                $short = null;
                $kind = null;
                $startByte = null;
                if ($node instanceof ClassLike && $node->name !== null) {
                    $short = $node->name->toString();
                    $kind = match (true) {
                        $node instanceof Interface_ => 'interface',
                        $node instanceof Trait_ => 'trait',
                        $node instanceof Enum_ => 'enum',
                        default => 'class',
                    };
                    $startByte = $node->name->getStartFilePos();
                } elseif ($node instanceof Function_) {
                    $short = $node->name->toString();
                    $kind = 'function';
                    $startByte = $node->name->getStartFilePos();
                }
                if ($short !== null && $startByte !== null && $startByte >= 0) {
                    $fqn = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $short
                        : $short;
                    $this->hits[] = [
                        'fqn' => $fqn,
                        'kind' => $kind,
                        'startByte' => $startByte,
                    ];
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->hits;
    }

    private static function shortOf(string $fqn): string
    {
        $idx = strrpos($fqn, '\\');
        return $idx === false ? $fqn : substr($fqn, $idx + 1);
    }

    /**
     * Map a byte offset within `$source` to (line, character) in LSP
     * 0-based coordinates.  Simple O(byteOffset) scan -- we only call this
     * for the small set of declaration identifiers, so a full PositionMap
     * (which precomputes line starts up front) would be overkill.
     *
     * @return array{0: int, 1: int}
     */
    private static function byteToLineChar(string $source, int $byteOffset): array
    {
        if ($byteOffset <= 0) {
            return [0, 0];
        }
        $clamped = min($byteOffset, strlen($source));
        $line = 0;
        $lineStart = 0;
        for ($i = 0; $i < $clamped; $i++) {
            if ($source[$i] === "\n") {
                $line++;
                $lineStart = $i + 1;
            }
        }
        return [$line, $clamped - $lineStart];
    }

    /**
     * Walk an AST collecting `FQN => paramNames` for every generic ClassLike.
     * Non-generic classes are skipped.  Methods on generic classes don't
     * count (method-scoped generics use a separate attribute path that this
     * index doesn't surface).
     *
     * @param list<Node\Stmt> $ast
     * @return array<string, list<string>>
     */
    private static function collectGenericClasses(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, list<string>> */
            public array $fqns = [];

            private string $currentNamespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                if (!$node instanceof ClassLike || $node->name === null) {
                    return null;
                }
                $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                if (!is_array($params) || $params === []) {
                    return null;
                }
                $paramNames = [];
                foreach ($params as $p) {
                    if ($p instanceof TypeParam) {
                        $paramNames[] = $p->name;
                    }
                }
                if ($paramNames === []) {
                    return null;
                }
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (!is_string($fqn)) {
                    // Generic classes always have ATTR_TEMPLATE_FQN stamped
                    // (set alongside ATTR_GENERIC_PARAMS), but fall back to
                    // namespace + short name just in case the parser ever
                    // diverges.
                    $short = $node->name->toString();
                    $fqn = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $short
                        : $short;
                }
                $this->fqns[$fqn] = $paramNames;
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->fqns;
    }

    /**
     * Collect generic-class bound FQNs per slot.  Mirrors
     * `collectGenericClasses` but yields the bound side of each
     * TypeParam (or null when the slot is unbounded) instead of the
     * placeholder names.
     *
     * @param list<Node\Stmt> $ast
     * @return array<string, list<?string>>
     */
    private static function collectGenericClassBounds(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, list<?string>> */
            public array $fqns = [];

            private string $currentNamespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                if (!$node instanceof ClassLike || $node->name === null) {
                    return null;
                }
                $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                if (!is_array($params) || $params === []) {
                    return null;
                }
                $bounds = [];
                foreach ($params as $p) {
                    if ($p instanceof TypeParam) {
                        // First leaf FQN keeps the existing string contract for
                        // the completion bound filter; the full expression tree
                        // is exposed separately for composite-bound support.
                        $leaves = BoundExprView::leafFqns($p->bound);
                        $bounds[] = $leaves[0] ?? null;
                    }
                }
                if ($bounds === []) {
                    return null;
                }
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (!is_string($fqn)) {
                    $short = $node->name->toString();
                    $fqn = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $short
                        : $short;
                }
                $this->fqns[$fqn] = $bounds;
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->fqns;
    }

    /**
     * Collect the full `BoundExpr` tree per slot for each generic class.
     * Mirrors `collectGenericClassBounds` but preserves the whole bound
     * expression (intersection / union / F-bounded), not just the first leaf
     * FQN -- this is what composite-bound completion filtering needs.
     *
     * @param list<Node\Stmt> $ast
     * @return array<string, list<?BoundExpr>>
     */
    private static function collectGenericClassBoundExprs(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, list<?BoundExpr>> */
            public array $exprs = [];

            private string $currentNamespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                if (!$node instanceof ClassLike || $node->name === null) {
                    return null;
                }
                $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                if (!is_array($params) || $params === []) {
                    return null;
                }
                $bounds = [];
                foreach ($params as $p) {
                    if ($p instanceof TypeParam) {
                        $bounds[] = $p->bound;
                    }
                }
                if ($bounds === []) {
                    return null;
                }
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (!is_string($fqn)) {
                    $short = $node->name->toString();
                    $fqn = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $short
                        : $short;
                }
                $this->exprs[$fqn] = $bounds;
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->exprs;
    }

    /**
     * Walk an AST for function- and method-scope generic placeholders.
     *
     * Both `function identity<T>(...)` and `Util::identity<T>(...)` are
     * stamped with `ATTR_METHOD_GENERIC_PARAMS` by `XphpSourceParser`
     * (a single attribute for both shapes, since methods are functions
     * with a receiver from the parser's POV).  We surface them so
     * `GenericParamRegistry::prettify` can strip the namespace prefix
     * worse-reflection attaches to bare `T` references inside the body
     * (`App\Demos\T` -> `T` for a function in `namespace App\Demos`).
     *
     * Key shape: `Namespace\funcName` for free functions,
     * `Namespace\ClassName::methodName` for class methods -- the registry
     * only needs the namespace prefix, but a unique-per-decl key keeps
     * subsequent overwrites from losing data.
     *
     * @param list<Node\Stmt> $ast
     * @return array<string, list<string>>
     */
    private static function collectGenericFunctionsAndMethods(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, list<string>> */
            public array $fqns = [];

            private string $currentNamespace = '';

            /** @var list<string>  enclosing class short-name stack (top = innermost) */
            private array $classStack = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                if ($node instanceof ClassLike) {
                    $this->classStack[] = $node->name?->toString() ?? '';
                    return null;
                }
                if (!$node instanceof Function_ && !$node instanceof ClassMethod) {
                    return null;
                }
                $params = $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                if (!is_array($params) || $params === []) {
                    return null;
                }
                $paramNames = [];
                foreach ($params as $p) {
                    if ($p instanceof TypeParam) {
                        $paramNames[] = $p->name;
                    }
                }
                if ($paramNames === []) {
                    return null;
                }
                $declName = $node->name->toString();
                if ($node instanceof ClassMethod) {
                    $className = end($this->classStack);
                    if ($className === false || $className === '') {
                        return null;
                    }
                    $key = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $className . '::' . $declName
                        : $className . '::' . $declName;
                } else {
                    $key = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $declName
                        : $declName;
                }
                $this->fqns[$key] = $paramNames;
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof ClassLike && $this->classStack !== []) {
                    array_pop($this->classStack);
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->fqns;
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    private static function findClassLikeInAst(array $ast, string $needle): ?ClassLike
    {
        $visitor = new class($needle) extends NodeVisitorAbstract {
            public ?ClassLike $found = null;

            public function __construct(private readonly string $needle)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->found !== null) {
                    return null;
                }
                if (!$node instanceof ClassLike) {
                    return null;
                }
                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (is_string($fqn) && $fqn === $this->needle) {
                    $this->found = $node;
                    return null;
                }
                // Fallback for non-generic classes that don't carry
                // ATTR_TEMPLATE_FQN: reconstruct from namespace + short
                // name walked from the AST itself.
                $current = $node->name?->toString();
                if ($current !== null) {
                    $ns = self::namespaceOf($node);
                    $built = $ns !== '' ? $ns . '\\' . $current : $current;
                    if ($built === $this->needle) {
                        $this->found = $node;
                    }
                }
                return null;
            }

            private static function namespaceOf(Node $node): string
            {
                $parent = $node->getAttribute('parent');
                while ($parent instanceof Node) {
                    if ($parent instanceof Node\Stmt\Namespace_) {
                        return $parent->name?->toString() ?? '';
                    }
                    $parent = $parent->getAttribute('parent');
                }
                return '';
            }
        };

        // Wrap the traversal so namespace context is tracked manually
        // (NameResolver would be heavier than we need; ATTR_TEMPLATE_FQN
        // covers the generic-class case and we only need the fallback for
        // non-generic classes).
        $tracker = new class($visitor) extends NodeVisitorAbstract {
            private string $currentNamespace = '';

            public function __construct(private readonly NodeVisitorAbstract $inner)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                if ($node instanceof ClassLike && $node->name !== null) {
                    if ($node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN) === null) {
                        $short = $node->name->toString();
                        $fqn = $this->currentNamespace !== ''
                            ? $this->currentNamespace . '\\' . $short
                            : $short;
                        $node->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, $fqn);
                    }
                }
                $this->inner->enterNode($node);
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($tracker);
        $traverser->traverse($ast);
        return $visitor->found;
    }
}
