<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

use RuntimeException;

/**
 * Categorical identifier for a diagnostic. Backed by the string the LSP wire
 * format expects in `Diagnostic.code`, so handlers can pass `$code->value`
 * straight through to phpactor's Diagnostic without translation.
 *
 * Codes intentionally use dotted notation (`xphp.<category>[.<subcategory>]`)
 * so an editor's code-aware config can pattern-match (e.g. "downgrade
 * xphp.parse.internal to info") without parsing message text.
 *
 * `fromRegistryRecordInstantiationException` is the central place that decides
 * which code applies to a `RuntimeException` thrown from
 * `Registry::recordInstantiation`. That method has two distinct error paths
 * (bound violation vs. hash collision) which were previously both surfaced as
 * `xphp.bound`. Mis-coding a collision as a bound violation pointed users at
 * the wrong fix (look at bounds vs. raise XPHP_HASH_LENGTH). Centralising the
 * triage here means future Registry exception paths slot in without
 * smearing magic strings across catch blocks.
 */
enum DiagnosticCode: string
{
    /** nikic/php-parser threw — the source isn't valid PHP after angle-stripping. */
    case Parse = 'xphp.parse';

    /** XphpSourceParser threw RuntimeException — internal contract violation, rare. */
    case ParseInternal = 'xphp.parse.internal';

    /** Registry::recordDefinition rejected a duplicate template declaration. */
    case Definition = 'xphp.definition';

    /** Registry::recordInstantiation rejected an arg list against its declared bound. */
    case BoundViolation = 'xphp.bound';

    /** Two distinct instantiations hashed to the same generated FQCN — raise XPHP_HASH_LENGTH. */
    case HashCollision = 'xphp.collision';

    /**
     * Bareword constant reference that doesn't resolve to a known
     * built-in pseudo-constant (null / true / false).  Conservative:
     * we only flag lowercase identifiers, since user-defined
     * constants overwhelmingly use UPPER_SNAKE_CASE and the LSP
     * doesn't yet maintain a workspace-wide constant index.
     *
     * Catches typos like `$x ?? nul` (PHP 8 throws a fatal
     * `Error: Undefined constant "nul"` at runtime for these).
     * Severity is Warning, not Error, because the heuristic is
     * intentionally narrow -- false positives are possible for
     * lowercase user-defined constants, and the warning level
     * keeps them dismissable.
     */
    case UndefinedName = 'xphp.undefined-name';

    /**
     * `new Foo(…)` (or generic `new Foo<T>(…)` after monomorphization)
     * was called with an argument whose type doesn't satisfy the
     * declared constructor parameter type.  Surfaces what would
     * otherwise be a runtime `TypeError` ahead of time.
     *
     * V1 only flags the cases where both sides are statically known:
     *  - param type is a class / interface / trait FQN, AND
     *    argument is either `new ClassName(...)` (so its type is the
     *    class FQN) or a `Stringable`-style scalar literal that
     *    obviously can't satisfy the class param;
     *  - param type is a scalar (string / int / float / bool / array)
     *    AND the argument is a literal of a different scalar kind.
     *
     * Skips arguments whose type can't be inferred from the AST alone
     * (variables, method-call results, ternaries, etc.) to avoid
     * false positives.
     */
    case ConstructorArgumentMismatch = 'xphp.ctor-arg-mismatch';

    /**
     * V2 of the argument-type checker, extending the constructor case
     * (`xphp.ctor-arg-mismatch`) to the other call shapes: instance
     * method calls (`$obj->m(…)`), static calls (`C::m(…)`), and
     * free-function calls (`fn(…)`).  Same conservative inference --
     * literals, `new C()`, and `$var`s locally assigned from those --
     * surfacing a would-be runtime `TypeError` ahead of time.
     *
     * A separate code (rather than reusing the constructor one) lets an
     * editor downgrade/scope the two independently and keeps the
     * constructor surface's history untouched.
     */
    case ArgumentMismatch = 'xphp.arg-mismatch';

    /**
     * A property/method was accessed via plain `->` on a receiver whose
     * static type is nullable -- a would-be runtime
     * `Error: Attempt to read property "x" on null` (or
     * `Call to a member function on null`).
     *
     * Conservative scope (low false-positive): fires ONLY when the immediate
     * receiver is itself a nullable member-access sub-expression -- a chain
     * like `$users->first()->name` where `first()` returns `?User` and no
     * inline guard is syntactically possible. Bare-variable receivers
     * (`$x->y`) are deferred to a future flow-narrowing pass, and a nullsafe
     * access (`$users->first()?->name`) is correct so it never fires.
     *
     * Severity is Warning, not Error: the inference is conservative but the
     * editor should keep it dismissable, matching `xphp.undefined-name`.
     */
    case NullDeref = 'xphp.null-deref';

    // ---------------------------------------------------------------------
    // xphp 0.3.0 generic-validation codes.
    //
    // The compiler's 0.3.0 `check` gate introduced a structured diagnostic
    // vocabulary. The LSP keeps computing diagnostics IN-PROCESS (it does not
    // shell out to `xphp check`), so a code is only *emitted* today if its
    // error reaches us as a `RuntimeException` from `Registry::recordInstantiation`
    // with no diagnostics-collector set. Verified against the vendor Registry:
    // only the two codes marked "(in-process)" below are actually emitted today
    // (too-many / missing type arguments). The rest are part of the vocabulary
    // for editor pattern-matching but only surface once the compiler's optional
    // diagnostics sink is wired in — a `Registry` built with a collector routes
    // variance-unprovable / bound-default / undefined-template through *appended*
    // diagnostics rather than throws (see DEFERRED).
    // ---------------------------------------------------------------------

    /** Too many type arguments for a generic template's parameter list. (in-process) */
    case TooManyTypeArguments = 'xphp.too_many_type_arguments';

    /** A required (no-default) type parameter was left unsupplied. (in-process) */
    case MissingTypeArgument = 'xphp.missing_type_argument';

    /** A member names a type that is not declared/imported (compiler `UndeclaredTypeParameterValidator`). Sink-only. */
    case UndeclaredType = 'xphp.undeclared_type';

    /** A variance edge could not be proven while instantiating a variant generic. Sink-only. */
    case BoundUnprovable = 'xphp.bound_unprovable';

    /** A `Closure(...)` signature type failed conformance (params/return/by-ref/arity). */
    case ClosureConformance = 'xphp.closure_conformance';

    /** A generic closure/arrow could not be specialized. */
    case UnspecializedGenericClosure = 'xphp.unspecialized_generic_closure';

    /** A generic call could not be resolved (e.g. turbofish-less generic call). */
    case UnresolvedGenericCall = 'xphp.unresolved_generic_call';

    /** A dynamically-named turbofish receiver could not be determined statically. */
    case UndeterminedReceiver = 'xphp.undetermined_receiver';

    /**
     * Map a RuntimeException raised by Registry::recordInstantiation to its
     * diagnostic code. The Registry doesn't use a typed exception hierarchy, so
     * we triage by a stable distinctive phrase in the message. 0.3.0 embeds the
     * (dynamic) template name near the start of most messages, so we match on
     * interior phrases with `str_contains` rather than a leading prefix. If a
     * builder's phrasing shifts, that code is lost and the bound fallback kicks
     * in — each phrase is locked by a unit test (`DiagnosticCodeTest`).
     *
     * Only the builders that `recordInstantiation` actually THROWS (with no
     * diagnostics-collector set, which is how `WorkspaceAnalyzer` builds the
     * Registry) are triaged here — verified against vendor `Registry.php`:
     * collisionMessage (:170), tooManyTypeArgumentsMessage (:350),
     * missingTypeArgumentMessage (:370), boundViolationMessage (:819), and
     * defaultBoundViolationMessage (:484, → bound). The variance-unprovable and
     * undefined-template messages are appended to a collector / thrown only on
     * the Compiler path, so they never reach this catch and are not triaged.
     */
    public static function fromRegistryRecordInstantiationException(RuntimeException $e): self
    {
        $message = $e->getMessage();

        return match (true) {
            str_starts_with($message, 'Hash collision') => self::HashCollision,
            str_contains($message, 'remove the extra argument') => self::TooManyTypeArguments,
            str_contains($message, 'has no default; supply it') => self::MissingTypeArgument,
            // "Generic bound violated while instantiating …" and "Default for
            // generic parameter `…` … violates the parameter's bound" are both
            // ordinary bound violations (the existing xphp.bound surface).
            default => self::BoundViolation,
        };
    }
}
