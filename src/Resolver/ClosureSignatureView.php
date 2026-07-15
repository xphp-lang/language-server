<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use XPHP\Transpiler\Monomorphize\ClosureSignature;
use XPHP\Transpiler\Monomorphize\ClosureSignatureParam;
use XPHP\Transpiler\Monomorphize\SigClosure;
use XPHP\Transpiler\Monomorphize\SigIntersection;
use XPHP\Transpiler\Monomorphize\SigRaw;
use XPHP\Transpiler\Monomorphize\SigType;
use XPHP\Transpiler\Monomorphize\SigTypeRef;
use XPHP\Transpiler\Monomorphize\SigUnion;
use XPHP\Transpiler\Monomorphize\TypeRef;

/**
 * Stateless view over an xphp 0.3.0 `Closure(...)` signature type.
 *
 * The parser stamps a `ClosureSignature` (via `XphpSourceParser::ATTR_CLOSURE_SIG`)
 * on the `Closure` type node in a parameter / return / property position. The
 * signature erases to a bare `\Closure` in emitted PHP, so the LSP is the only
 * place the structured form (params + return, DNF, generic args) survives for
 * display. This helper renders that tree to a source-shaped string so hover /
 * semantic-token / completion consumers don't re-walk it.
 *
 * A closure *type* carries no parameter names (they aren't part of the type), so
 * params render as bare types with `&` (by-ref) / `...` (variadic) modifiers.
 */
final class ClosureSignatureView
{
    /**
     * Render a signature as `Closure(int, string): bool`, `?Closure(...)` when
     * the whole type is nullable, `Closure(int&, string...): void`, and DNF
     * `Closure((A&B)|C): void` for composite member/return types. A null return
     * (untyped closure) omits the `: <return>` suffix.
     */
    public static function render(ClosureSignature $sig): string
    {
        $params = implode(', ', array_map(
            static fn (ClosureSignatureParam $p): string => self::renderParam($p),
            $sig->params,
        ));
        $out = ($sig->nullable ? '?' : '') . 'Closure(' . $params . ')';
        if ($sig->return !== null) {
            $out .= ': ' . self::renderSigType($sig->return);
        }
        return $out;
    }

    private static function renderParam(ClosureSignatureParam $param): string
    {
        $rendered = self::renderSigType($param->type);
        if ($param->byRef) {
            $rendered .= '&';
        }
        if ($param->variadic) {
            $rendered .= '...';
        }
        return $rendered;
    }

    /**
     * Render a `SigType` node:
     *   - leaf (`SigTypeRef`) -> the wrapped `TypeRef`'s display (handles generic
     *     args, e.g. `Box<int>`);
     *   - union               -> `A|B`, with intersection members parenthesised
     *     for DNF (`(A&B)|C`);
     *   - intersection        -> `A&B`.
     * An unknown `SigType` subtype degrades to an empty string rather than
     * throwing (forward-compatible with future node kinds).
     */
    private static function renderSigType(SigType $type): string
    {
        if ($type instanceof SigTypeRef) {
            return self::renderTypeRef($type->type);
        }
        if ($type instanceof SigClosure) {
            // A nested closure type, e.g. `Closure(Closure(int): bool): void`.
            return self::render($type->signature);
        }
        if ($type instanceof SigRaw) {
            // Forms the token scanner keeps as sliced source (composite/DNF like
            // `(A&B)|C`) are already display-ready.
            return $type->raw;
        }
        if ($type instanceof SigUnion) {
            return implode('|', array_map(
                static fn (SigType $m): string => self::wrapForUnion($m),
                $type->members,
            ));
        }
        if ($type instanceof SigIntersection) {
            return implode('&', array_map(
                static fn (SigType $m): string => self::renderSigType($m),
                $type->members,
            ));
        }
        return '';
    }

    /**
     * Render a leaf `TypeRef`: type-params and scalars are bare, a class leaf
     * gets the leading `\` of a fully-qualified name (matching the rest of the
     * hover card), and generic args recurse.
     */
    private static function renderTypeRef(TypeRef $type): string
    {
        $name = ($type->isTypeParam || $type->isScalar)
            ? $type->name
            : '\\' . ltrim($type->name, '\\');
        if ($type->args === []) {
            return $name;
        }
        return $name . '<' . implode(', ', array_map(
            static fn (TypeRef $arg): string => self::renderTypeRef($arg),
            $type->args,
        )) . '>';
    }

    /**
     * A union member that is itself an intersection is parenthesised to preserve
     * DNF grouping (`(A&B)|C`); everything else renders bare.
     */
    private static function wrapForUnion(SigType $member): string
    {
        $rendered = self::renderSigType($member);
        return $member instanceof SigIntersection ? '(' . $rendered . ')' : $rendered;
    }
}
