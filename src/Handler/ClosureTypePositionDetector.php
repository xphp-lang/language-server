<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

/**
 * Decide whether a cursor sits at a *type* token inside an xphp 0.3.0
 * `Closure(int $x, string $y): bool` signature type, so completion can offer
 * class / scalar type names there. A closure signature type is the only place
 * `Closure(` is followed by a type list, so the opener `Closure(` (not preceded
 * by `new`) is the anchor.
 *
 * Three type positions are recognised, each identified by the non-whitespace
 * byte immediately left of the cursor's typed prefix:
 *   - `Closure(|`            -> `(` that opens a `Closure(` group (first param);
 *   - `Closure(int $x, |`    -> a `,` at the top level of that group (next param);
 *   - `Closure(int $x): |`   -> a `:` after the group's closing `)` (return type).
 *
 * Depth tracking over `()`, `<>` and `[]` keeps a comma inside a nested generic
 * (`Closure(Map<K, V> $m, |`) or a nested closure from being mistaken for a
 * top-level separator. A parameter *name* position (`Closure(int |`, i.e. right
 * after a type, before `$x`) is deliberately NOT a type position and returns null.
 *
 * Conservative by design: an unrecognised or ambiguous shape returns null (no
 * completion) rather than firing in the wrong context.
 */
final readonly class ClosureTypePositionDetector
{
    /**
     * @return array{prefix: string}|null
     *   null  -> cursor is not at a closure-signature type position
     *   array -> `prefix` is the identifier stem typed since the opener /
     *            separator (used to filter candidates).
     */
    public static function detect(string $source, int $offset): ?array
    {
        if ($offset < 0 || $offset > strlen($source)) {
            return null;
        }

        // The identifier stem immediately left of the cursor.
        $stemStart = $offset;
        while ($stemStart > 0 && self::isTypeByte($source[$stemStart - 1])) {
            $stemStart--;
        }
        $prefix = substr($source, $stemStart, $offset - $stemStart);

        // The first non-whitespace byte before the stem decides the position.
        $i = $stemStart - 1;
        while ($i >= 0 && self::isSpace($source[$i])) {
            $i--;
        }
        if ($i < 0) {
            return null;
        }

        $anchor = $source[$i];
        if ($anchor === '(') {
            return self::isClosureOpener($source, $i) ? ['prefix' => $prefix] : null;
        }
        if ($anchor === ',') {
            $open = self::enclosingOpenParen($source, $i - 1);
            return $open !== null && self::isClosureOpener($source, $open) ? ['prefix' => $prefix] : null;
        }
        if ($anchor === ':') {
            // Return-type position: `): |`. A `::` (static access) is not it.
            if ($i > 0 && $source[$i - 1] === ':') {
                return null;
            }
            $j = $i - 1;
            while ($j >= 0 && self::isSpace($source[$j])) {
                $j--;
            }
            if ($j < 0 || $source[$j] !== ')') {
                return null;
            }
            $open = self::matchingOpenParen($source, $j);
            return $open !== null && self::isClosureOpener($source, $open) ? ['prefix' => $prefix] : null;
        }

        return null;
    }

    /**
     * True when the `(` at `$parenPos` opens a `Closure(` type — i.e. it is
     * immediately (modulo whitespace) preceded by the bare word `Closure`, and
     * that word is not itself preceded by `new` (a `new Closure(` constructor
     * call is not a signature type).
     */
    private static function isClosureOpener(string $source, int $parenPos): bool
    {
        $i = $parenPos - 1;
        while ($i >= 0 && self::isSpace($source[$i])) {
            $i--;
        }
        $end = $i;
        while ($i >= 0 && self::isIdentByte($source[$i])) {
            $i--;
        }
        $word = substr($source, $i + 1, $end - $i);
        if (strcasecmp($word, 'Closure') !== 0) {
            return false;
        }
        // A leading namespace separator (`\Closure`, `Foo\Closure`) is not the
        // signature-type spelling, which is the bare keyword.
        if ($i >= 0 && $source[$i] === '\\') {
            return false;
        }
        // Guard against `new Closure(`.
        $k = $i;
        while ($k >= 0 && self::isSpace($source[$k])) {
            $k--;
        }
        $wordEnd = $k;
        while ($k >= 0 && self::isIdentByte($source[$k])) {
            $k--;
        }
        return strcasecmp(substr($source, $k + 1, $wordEnd - $k), 'new') !== 0;
    }

    /**
     * Walk back from `$from` to the `(` that encloses it at bracket depth 0,
     * skipping balanced `()`, `<>` and `[]`. Returns the opener offset, or null
     * if none is found before the start of source.
     */
    private static function enclosingOpenParen(string $source, int $from): ?int
    {
        $paren = 0;
        $angle = 0;
        $square = 0;
        for ($i = $from; $i >= 0; $i--) {
            $c = $source[$i];
            if ($c === ')') {
                $paren++;
            } elseif ($c === '>') {
                $angle++;
            } elseif ($c === ']') {
                $square++;
            } elseif ($c === '(') {
                if ($paren === 0) {
                    return $i;
                }
                $paren--;
            } elseif ($c === '<') {
                if ($angle > 0) {
                    $angle--;
                }
            } elseif ($c === '[') {
                if ($square > 0) {
                    $square--;
                }
            } elseif ($c === ';' || $c === '{' || $c === '}') {
                // Statement boundary -- we are not inside a paren group.
                return null;
            }
        }
        return null;
    }

    /**
     * Given the offset of a `)`, return the offset of its matching `(`.
     */
    private static function matchingOpenParen(string $source, int $closePos): ?int
    {
        $depth = 0;
        for ($i = $closePos; $i >= 0; $i--) {
            $c = $source[$i];
            if ($c === ')') {
                $depth++;
            } elseif ($c === '(') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    private static function isTypeByte(string $byte): bool
    {
        return ctype_alnum($byte) || $byte === '_' || $byte === '\\';
    }

    private static function isIdentByte(string $byte): bool
    {
        return ctype_alnum($byte) || $byte === '_';
    }

    private static function isSpace(string $byte): bool
    {
        return $byte === ' ' || $byte === "\t" || $byte === "\n" || $byte === "\r";
    }
}
