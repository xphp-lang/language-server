<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

/**
 * A plain-`->` member access whose immediate receiver is a statically nullable
 * sub-expression -- a potential null dereference the resolver surfaced.
 *
 * Offsets are in the xphp-STRIPPED source coordinate space (the AST the
 * resolver walks); the diagnostics layer translates them back to the original
 * buffer via `ByteOffsetMap` before emitting an LSP range. `member` is the
 * accessed property/method name (for the message); `receiverType` is the
 * inferred nullable type rendered for the user (e.g. `?App\Models\User`).
 */
final readonly class NullDerefSite
{
    public function __construct(
        public int $strippedStart,
        public int $strippedEnd,
        public string $member,
        public string $receiverType,
    ) {
    }
}
