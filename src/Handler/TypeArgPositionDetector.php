<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

/**
 * Decide whether a cursor position is inside a call-site generic-args clause —
 * i.e. inside the turbofish `Name::<…>` that 0.2.x requires at expression
 * context.
 *
 * The backward depth-walk + `::` opener guard lives in [[TurbofishScanner]];
 * [[detect]] delegates to it so completion, hover, and the analyzer share one
 * notion of "inside a turbofish clause". [[identifierAt]] adds the forward scan
 * for the full identifier under the cursor (used by go-to-definition on a
 * type-arg class name).
 *
 * Limits intentionally accepted:
 *  - Doesn't bind the surrounding Name to the candidate filter (so we can't
 *    yet prune by "bounds declared on T of Box"). That requires AST work
 *    and lands alongside bound-aware completion later.
 */
final readonly class TypeArgPositionDetector
{
    /**
     * @return array{prefix: string, containerName: string, slot: int}|null
     *   null  → cursor is not in a type-arg position
     *   array → cursor IS in a type-arg position.
     *           `prefix`        - substring typed since the last `<` or `,`
     *                             (post-whitespace), used to filter candidates.
     *           `containerName` - the Name preceding the unmatched `<` (the
     *                             generic class / function whose type-args we
     *                             are inside).  Same form as it appeared in
     *                             source -- may be a short name (`Box`) or a
     *                             qualified one (`App\Box`).
     *           `slot`          - 0-based index of the type-arg slot the
     *                             cursor sits in (0 for `Box<|`, 1 for
     *                             `Pair<Foo, |`, ...).  Used by bound-aware
     *                             completion to pick the relevant bound.
     */
    public static function detect(string $source, int $offset): ?array
    {
        // Call-site generic args now use the turbofish `Name::<Args>`; the
        // shared scanner owns the backward depth-walk and the `::` opener
        // guard so completion, hover, and the analyzer stay in lockstep.
        return TurbofishScanner::detectCursorInClause($source, $offset);
    }

    /**
     * Full identifier under the cursor, only when the cursor is inside a
     * generic `<…>` clause AND on (or adjacent to) identifier bytes.
     *
     * Built on top of [[detect]]: that method returns the prefix to the LEFT
     * of the cursor; here we additionally scan FORWARD from the cursor and
     * concatenate the trailing identifier bytes, yielding the full identifier
     * span the user is actually pointing at.
     *
     * Returns null when:
     *  - the cursor isn't in a type-arg position (whatever [[detect]]
     *    decides), or
     *  - there's no identifier byte at the cursor and no prefix to the left
     *    (e.g. cursor on whitespace inside `<…>`).
     *
     * Used by the definition handler for Ctrl+click on a type-arg class name
     * like `User` in `identity<User>(...)`.  The completion handler uses the
     * `detect`-only prefix because completion needs the typed-so-far stem,
     * not the full identifier including the suffix the user hasn't typed.
     */
    public static function identifierAt(string $source, int $offset): ?string
    {
        $context = self::detect($source, $offset);
        if ($context === null) {
            return null;
        }

        // Walk forward from the cursor capturing the trailing identifier
        // bytes -- the part of the name to the RIGHT of the cursor that the
        // user has already typed.
        $length = strlen($source);
        $end = $offset;
        while ($end < $length && self::isIdentifierByte($source[$end])) {
            $end++;
        }
        $suffix = substr($source, $offset, $end - $offset);

        $full = $context['prefix'] . $suffix;
        return $full === '' ? null : $full;
    }

    private static function isIdentifierByte(string $byte): bool
    {
        return ctype_alnum($byte) || $byte === '_' || $byte === '\\';
    }
}
