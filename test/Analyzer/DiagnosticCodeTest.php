<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Analyzer;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Lsp\Analyzer\DiagnosticCode;

/**
 * The triage helper is the central place that decides which code applies to a
 * `RuntimeException` from `Registry::recordInstantiation` — bound violation
 * vs. hash collision. Tested in isolation so a phrasing change in Registry's
 * error builders surfaces here loudly rather than via miscategorised
 * diagnostics in the editor.
 */
final class DiagnosticCodeTest extends TestCase
{
    public function testHashCollisionMessageMapsToCollisionCode(): void
    {
        // Phrasing must match Registry::collisionMessage's leading line.
        $e = new RuntimeException(
            "Hash collision detected while monomorphizing generics.\n"
            . 'Two distinct instantiations produced the same specialized FQCN...'
        );

        self::assertSame(
            DiagnosticCode::HashCollision,
            DiagnosticCode::fromRegistryRecordInstantiationException($e),
        );
    }

    public function testBoundViolationMessageMapsToBoundViolationCode(): void
    {
        // Phrasing must match Registry::validateBounds's leading line.
        $e = new RuntimeException(
            "Generic bound violated while instantiating App\\Box<int>.\n"
            . '  type parameter T is bounded by Stringable...'
        );

        self::assertSame(
            DiagnosticCode::BoundViolation,
            DiagnosticCode::fromRegistryRecordInstantiationException($e),
        );
    }

    public function testCompositeBoundViolationMessageMapsToBoundViolationCode(): void
    {
        // Composite bounds share the same leading line but use "does not
        // satisfy" in the detail (vs "does not extend/implement" for a single
        // leaf) -- the triage keys off the prefix, so both route the same way.
        $e = new RuntimeException(
            "Generic bound violated while instantiating App\\Pair<int>.\n"
            . "  type parameter T is bounded by App\\Animal & App\\Comparable\n"
            . "  but the supplied concrete type is int\n\n"
            . '  "int" does not satisfy "App\\Animal & App\\Comparable".'
        );

        self::assertSame(
            DiagnosticCode::BoundViolation,
            DiagnosticCode::fromRegistryRecordInstantiationException($e),
        );
    }

    public function testUnknownPrefixFallsBackToBoundViolation(): void
    {
        // Conservative default: an unfamiliar message phrasing is more likely
        // to be a bound issue than a collision (the latter is rare and
        // intentionally distinct in its phrasing). The fallback keeps the
        // diagnostic from looking dropped while flagging that Registry's
        // error catalog has grown a path we didn't categorise.
        $e = new RuntimeException('Something unexpected happened.');

        self::assertSame(
            DiagnosticCode::BoundViolation,
            DiagnosticCode::fromRegistryRecordInstantiationException($e),
        );
    }

    public function testTooManyTypeArgumentsMapsToCode(): void
    {
        // Registry::tooManyTypeArgumentsMessage (0.3.0) — distinctive interior
        // phrase "remove the extra argument".
        $e = new RuntimeException(
            'Generic template "App\\Box" declares 1 type parameter(s) but was '
            . 'instantiated with 2 type argument(s); remove the extra argument(s).'
        );

        self::assertSame(
            DiagnosticCode::TooManyTypeArguments,
            DiagnosticCode::fromRegistryRecordInstantiationException($e),
        );
    }

    public function testMissingTypeArgumentMapsToCode(): void
    {
        // Registry::missingTypeArgumentMessage (0.3.0) — "has no default; supply it".
        $e = new RuntimeException(
            'Generic template "App\\Box" was instantiated with 0 type argument(s) '
            . 'but parameter `T` (position 1) has no default; supply it explicitly '
            . 'or add defaults to every preceding required parameter.'
        );

        self::assertSame(
            DiagnosticCode::MissingTypeArgument,
            DiagnosticCode::fromRegistryRecordInstantiationException($e),
        );
    }

    public function testDefaultBoundViolationFallsBackToBoundViolation(): void
    {
        // Registry::defaultBoundViolationMessage — a default that violates its
        // param's bound is still an ordinary bound violation (xphp.bound).
        $e = new RuntimeException(
            "Default for generic parameter `T` of \"App\\Box\" violates the parameter's bound.\n"
            . "  bound:   Stringable\n  default: int\n  reason:  int is not Stringable"
        );

        self::assertSame(
            DiagnosticCode::BoundViolation,
            DiagnosticCode::fromRegistryRecordInstantiationException($e),
        );
    }

    public function testEachCodeMapsToTheLspWireString(): void
    {
        // Locks the backed-enum values that the LSP wire format depends on
        // (via DiagnosticTranslator). Renaming a case without updating the
        // backed value would silently break editor-side code-pattern
        // matching ("xphp.bound" -> "xphp.boundViolation" e.g.).
        self::assertSame('xphp.parse', DiagnosticCode::Parse->value);
        self::assertSame('xphp.parse.internal', DiagnosticCode::ParseInternal->value);
        self::assertSame('xphp.definition', DiagnosticCode::Definition->value);
        self::assertSame('xphp.bound', DiagnosticCode::BoundViolation->value);
        self::assertSame('xphp.collision', DiagnosticCode::HashCollision->value);
        // xphp 0.3.0 generic-validation codes.
        self::assertSame('xphp.too_many_type_arguments', DiagnosticCode::TooManyTypeArguments->value);
        self::assertSame('xphp.missing_type_argument', DiagnosticCode::MissingTypeArgument->value);
        self::assertSame('xphp.undeclared_type', DiagnosticCode::UndeclaredType->value);
        self::assertSame('xphp.bound_unprovable', DiagnosticCode::BoundUnprovable->value);
        self::assertSame('xphp.closure_conformance', DiagnosticCode::ClosureConformance->value);
        self::assertSame('xphp.unspecialized_generic_closure', DiagnosticCode::UnspecializedGenericClosure->value);
        self::assertSame('xphp.unresolved_generic_call', DiagnosticCode::UnresolvedGenericCall->value);
        self::assertSame('xphp.undetermined_receiver', DiagnosticCode::UndeterminedReceiver->value);
    }
}
