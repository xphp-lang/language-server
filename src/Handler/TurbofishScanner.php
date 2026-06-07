<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

/**
 * Byte-level scanner for the call-site turbofish clause `Name::<Args>`.
 *
 * 0.2.x requires the turbofish at expression-context generic calls
 * (`new Box::<T>()`, `Foo::method::<T>(...)`, `$obj->m::<T>(...)`) and rejects
 * the old bare-`<` call syntax. Several handlers need to find the `::<…>`
 * clause -- to know whether the cursor is inside it, or to map the clause's
 * byte range. This helper centralises that scan so the depth-walk, the `::`
 * opener guard, and the comma-splitting live in one place.
 *
 * Declaration clauses (`class Box<T>`, `function f<T>`) keep the bare `<` and
 * are NOT handled here -- they never reach an expression-context call scan.
 *
 * No string/comment awareness: turbofish syntax never appears inside strings
 * or comments, and the parser-level scanner already handles those for parsing.
 */
final class TurbofishScanner
{
    /**
     * Decide whether `$offset` sits inside a turbofish clause and, if so, which
     * container and which arg slot.
     *
     * Walks backward from the cursor with a `<>` depth counter; the unmatched
     * depth-0 `<` opens a turbofish clause only when the preceding
     * non-whitespace bytes are `::` (the container name is read to the LEFT of
     * the `::`). Handles `Foo::<` (cursor right after `<`) and `Foo::<>` (empty
     * / all-defaults).
     *
     * @return array{prefix: string, containerName: string, slot: int}|null
     *   null  → not inside a turbofish clause.
     *   array → `prefix` is the partial identifier typed since the last `<` or
     *           `,`; `containerName` is the receiver name left of `::` (short
     *           or qualified, as it appears in source); `slot` is the 0-based
     *           arg index.
     */
    public static function detectCursorInClause(string $source, int $offset): ?array
    {
        $length = strlen($source);
        if ($offset < 0 || $offset > $length) {
            return null;
        }

        $prefixStart = $offset;
        while ($prefixStart > 0 && self::isIdentifierByte($source[$prefixStart - 1])) {
            $prefixStart--;
        }
        $prefix = substr($source, $prefixStart, $offset - $prefixStart);

        // The innermost unmatched `<` gives the container the cursor is typing
        // an arg for, plus the slot index. That innermost clause may be a bare
        // nested type-arg (`Box::<List<|>`) -- the container `List` is bare --
        // so we record it first, then verify the whole expression is anchored
        // by a turbofish `::<` somewhere outward.
        $containerName = null;
        $slot = 0;
        $depth = 0;
        $i = $prefixStart - 1;
        while ($i >= 0) {
            $c = $source[$i];
            if ($c === '>') {
                $depth++;
                $i--;
                continue;
            }
            if ($c === ',' && $depth === 0 && $containerName === null) {
                $slot++;
                $i--;
                continue;
            }
            if ($c === '<' && $depth > 0) {
                $depth--;
                $i--;
                continue;
            }
            if ($c === '<') {
                // Unmatched depth-0 `<`. A turbofish opener has `::` directly
                // to its left (modulo whitespace); the container name is left
                // of that `::`. A bare nested type-arg clause has an identifier
                // directly to its left instead.
                $beforeAngle = self::skipSpaceLeft($source, $i - 1);
                if (self::isDoubleColonAt($source, $beforeAngle)) {
                    [$name] = self::nameLeftOf($source, $beforeAngle - 1);
                    if ($name === null) {
                        // `::<` with no receiver name -- not a turbofish.
                        return null;
                    }
                    return [
                        'prefix' => $prefix,
                        'containerName' => $containerName ?? $name,
                        'slot' => $slot,
                    ];
                }
                // Bare `<`: only valid as a nested type-arg inside an outer
                // turbofish. Capture the innermost container once, then keep
                // walking outward to find the enclosing turbofish anchor.
                [$name, $beforeName] = self::nameLeftOf($source, $i);
                if ($name === null) {
                    return null;
                }
                if ($containerName === null) {
                    $containerName = $name;
                }
                $i = $beforeName;
                continue;
            }
            if (self::isInterArgByte($c) || self::isIdentifierByte($c)) {
                $i--;
                continue;
            }

            // Anything else breaks the clause context.
            return null;
        }

        return null;
    }

    /**
     * Read the identifier ending just before byte `$openPos` (the `<`), skipping
     * intervening whitespace. Returns `[name|null, indexBeforeName]` where
     * `indexBeforeName` is the byte index immediately to the left of the name
     * (which the caller inspects for a `::`).
     *
     * @return array{0: ?string, 1: int}
     */
    private static function nameLeftOf(string $source, int $openPos): array
    {
        $nameEnd = $openPos; // exclusive
        while ($nameEnd > 0 && self::isSpace($source[$nameEnd - 1])) {
            $nameEnd--;
        }
        $nameStart = $nameEnd;
        while ($nameStart > 0 && self::isIdentifierByte($source[$nameStart - 1])) {
            $nameStart--;
        }
        if ($nameStart === $nameEnd) {
            return [null, $nameStart - 1];
        }

        return [substr($source, $nameStart, $nameEnd - $nameStart), $nameStart - 1];
    }

    /**
     * Skip whitespace bytes leftward from `$index`, returning the index of the
     * first non-space byte at or before it (may be -1).
     */
    private static function skipSpaceLeft(string $source, int $index): int
    {
        while ($index >= 0 && self::isSpace($source[$index])) {
            $index--;
        }

        return $index;
    }

    /**
     * Is there a `::` ending exactly at byte `$index`?
     */
    private static function isDoubleColonAt(string $source, int $index): bool
    {
        return $index >= 1 && $source[$index] === ':' && $source[$index - 1] === ':';
    }

    /**
     * Locate the turbofish clause byte range immediately following a name that
     * ends at `$nameEnd` (inclusive). Requires `::<` (whitespace permitted
     * around the `::` and between `::` and `<`). Returns the byte positions of
     * the opening `<` and its matching `>`, or null when no clause is present
     * or it is unterminated.
     *
     * @return array{openPos: int, closePos: int}|null
     */
    public static function clauseAfter(string $source, int $nameEnd): ?array
    {
        $n = strlen($source);
        $i = $nameEnd + 1;
        while ($i < $n && self::isSpace($source[$i])) {
            $i++;
        }
        // Require the `::` opener.
        if ($i + 1 >= $n || $source[$i] !== ':' || $source[$i + 1] !== ':') {
            return null;
        }
        $i += 2;
        while ($i < $n && self::isSpace($source[$i])) {
            $i++;
        }
        if ($i >= $n || $source[$i] !== '<') {
            return null;
        }
        $openPos = $i;
        $depth = 1;
        $j = $i + 1;
        while ($j < $n && $depth > 0) {
            $c = $source[$j];
            if ($c === '<') {
                $depth++;
            } elseif ($c === '>') {
                $depth--;
            }
            $j++;
        }
        if ($depth !== 0) {
            return null;
        }

        return ['openPos' => $openPos, 'closePos' => $j - 1];
    }

    /**
     * Split the inner text of a clause (the bytes between `<` and `>`) into its
     * top-level arguments, honouring nested `<…>`. Empty inner text yields an
     * empty list (the `Foo::<>` all-defaults case).
     *
     * @return list<string>
     */
    public static function splitTopLevelArgs(string $clauseInner): array
    {
        if (trim($clauseInner) === '') {
            return [];
        }
        $args = [];
        $depth = 0;
        $current = '';
        $len = strlen($clauseInner);
        for ($i = 0; $i < $len; $i++) {
            $c = $clauseInner[$i];
            if ($c === '<') {
                $depth++;
                $current .= $c;
            } elseif ($c === '>') {
                if ($depth > 0) {
                    $depth--;
                }
                $current .= $c;
            } elseif ($c === ',' && $depth === 0) {
                $args[] = trim($current);
                $current = '';
            } else {
                $current .= $c;
            }
        }
        $args[] = trim($current);

        return $args;
    }

    /**
     * Index of the top-level arg containing `$offset` within a clause's inner
     * text (between `<` and `>` exclusive). Counts `,` at nesting depth 0;
     * nested `<…>` clauses don't split the outer arg.
     */
    public static function topLevelArgIndexAt(string $innerText, int $offset): ?int
    {
        $n = strlen($innerText);
        if ($offset < 0 || $offset > $n) {
            return null;
        }
        $depth = 0;
        $index = 0;
        for ($i = 0; $i < $offset; $i++) {
            $c = $innerText[$i];
            if ($c === '<') {
                $depth++;
            } elseif ($c === '>') {
                if ($depth > 0) {
                    $depth--;
                }
            } elseif ($c === ',' && $depth === 0) {
                $index++;
            }
        }

        return $index;
    }

    private static function isIdentifierByte(string $byte): bool
    {
        return ctype_alnum($byte) || $byte === '_' || $byte === '\\';
    }

    private static function isSpace(string $byte): bool
    {
        return $byte === ' ' || $byte === "\t" || $byte === "\n" || $byte === "\r";
    }

    private static function isInterArgByte(string $byte): bool
    {
        return self::isSpace($byte) || $byte === ',';
    }
}
