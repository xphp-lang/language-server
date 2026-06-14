<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node\Stmt\ClassLike;

/**
 * A resolved {@see ClassLike} together with its DECLARING file's
 * name-resolution context (use-map + namespace).
 *
 * Returned by {@see ClassLikeLookup::findWithContext}. The context lets the
 * resolver qualify a bare class name written in that class's member types
 * (`public ?User $bestFriend`) against the file where it was declared --
 * including cross-namespace `use` imports -- rather than guessing from the
 * referencing call site's namespace.
 */
final readonly class ClassLikeContext
{
    /**
     * @param array<string, string> $useMap alias -> FQN, from the declaring file
     */
    public function __construct(
        public ClassLike $classLike,
        public array $useMap,
        public string $namespace,
    ) {
    }
}
