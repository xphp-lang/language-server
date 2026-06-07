<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use XPHP\Transpiler\Monomorphize\BoundExpr;
use XPHP\Transpiler\Monomorphize\BoundIntersection;
use XPHP\Transpiler\Monomorphize\BoundLeaf;
use XPHP\Transpiler\Monomorphize\BoundUnion;
use XPHP\Transpiler\Monomorphize\TypeRef;

/**
 * Stateless view over a type parameter's `BoundExpr` tree.
 *
 * The parser stores a bound as a small expression tree -- a single
 * `BoundLeaf`, or a `BoundIntersection` / `BoundUnion` of further bounds (DNF
 * nests a union of intersections). This helper flattens and renders that tree
 * for the parts of the server that only need a display string, the set of leaf
 * FQNs, or a satisfaction verdict, so each handler doesn't re-walk the tree.
 */
final class BoundExprView
{
    /**
     * Human-readable, source-shaped rendering of a bound:
     *   - leaf            -> `\Fqn` (or `\Comparable<\T>` for F-bounded forms)
     *   - intersection    -> `A & B`
     *   - union           -> `A | B`
     * Returns null for an absent bound.
     */
    public static function displayString(?BoundExpr $bound): ?string
    {
        if ($bound === null) {
            return null;
        }
        if ($bound instanceof BoundLeaf) {
            return self::renderTypeRef($bound->type);
        }
        if ($bound instanceof BoundIntersection) {
            return implode(' & ', array_map(
                static fn (BoundExpr $op): string => self::wrap($op, $bound),
                $bound->operands,
            ));
        }
        if ($bound instanceof BoundUnion) {
            return implode(' | ', array_map(
                static fn (BoundExpr $op): string => self::wrap($op, $bound),
                $bound->operands,
            ));
        }

        return null;
    }

    /**
     * Flatten every leaf FQN in the tree, with the leading `\` stripped so the
     * names compare equal to the hierarchy's canonical form.
     *
     * @return list<string>
     */
    public static function leafFqns(?BoundExpr $bound): array
    {
        if ($bound === null) {
            return [];
        }
        if ($bound instanceof BoundLeaf) {
            return [ltrim($bound->type->name, '\\')];
        }
        if ($bound instanceof BoundIntersection || $bound instanceof BoundUnion) {
            $out = [];
            foreach ($bound->operands as $operand) {
                foreach (self::leafFqns($operand) as $leaf) {
                    $out[] = $leaf;
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * Does `$candidateFqn` satisfy the bound, given a subtype oracle?
     *   - null bound  -> true (unbounded)
     *   - leaf        -> `$isSubtype($candidate, $leafFqn)`
     *   - intersection-> every operand must hold
     *   - union       -> any operand suffices
     *
     * @param callable(string, string): bool $isSubtype
     */
    public static function isSatisfiedBy(string $candidateFqn, ?BoundExpr $bound, callable $isSubtype): bool
    {
        if ($bound === null) {
            return true;
        }
        if ($bound instanceof BoundLeaf) {
            return $isSubtype($candidateFqn, ltrim($bound->type->name, '\\'));
        }
        if ($bound instanceof BoundIntersection) {
            foreach ($bound->operands as $operand) {
                if (!self::isSatisfiedBy($candidateFqn, $operand, $isSubtype)) {
                    return false;
                }
            }

            return true;
        }
        if ($bound instanceof BoundUnion) {
            foreach ($bound->operands as $operand) {
                if (self::isSatisfiedBy($candidateFqn, $operand, $isSubtype)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Render a leaf's `TypeRef`, recursing into its generic args so F-bounded
     * shapes like `Comparable<T>` survive in the display string.
     */
    private static function renderTypeRef(TypeRef $type): string
    {
        // Type-param references (the `T` in an F-bounded `Comparable<T>`) and
        // scalars are bare identifiers; only a class/interface leaf gets the
        // leading `\` of a fully-qualified name.
        $name = ($type->isTypeParam || $type->isScalar)
            ? $type->name
            : '\\' . ltrim($type->name, '\\');
        if ($type->args === []) {
            return $name;
        }

        return sprintf(
            '%s<%s>',
            $name,
            implode(', ', array_map(
                static fn (TypeRef $arg): string => self::renderTypeRef($arg),
                $type->args,
            )),
        );
    }

    /**
     * Parenthesise an operand whose precedence is looser than its parent, so a
     * DNF tree (`(A | B) & C`) renders unambiguously under PHP's `&` > `|`
     * convention.
     */
    private static function wrap(BoundExpr $operand, BoundExpr $parent): string
    {
        $rendered = (string) self::displayString($operand);
        $needsParens = ($parent instanceof BoundIntersection && $operand instanceof BoundUnion)
            || ($parent instanceof BoundUnion && $operand instanceof BoundIntersection);

        return $needsParens ? '(' . $rendered . ')' : $rendered;
    }
}
