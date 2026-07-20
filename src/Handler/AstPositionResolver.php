<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Walks an AST and locates the smallest Name node whose byte range contains a
 * given offset. Also captures the stack of enclosing ClassLike nodes AND the
 * stack of enclosing function-likes (methods / functions / closures) at the hit
 * site — handlers need the former to resolve a class type-param (`T` in
 * `public T $item`) and the latter to resolve a *method*-level type-param
 * (`U` in `function contains<U : E>(U $value)`), whose bound may itself
 * reference the enclosing class param.
 *
 * Used by HoverHandler (and, later, DefinitionHandler) — extracted so the
 * resolution logic is testable in isolation.
 */
final readonly class AstPositionResolver
{
    /**
     * @param list<Node\Stmt> $ast
     * @return array{name: Name, classScope: list<ClassLike>, methodScope: list<FunctionLike>}|null
     */
    public static function nameAtOffset(array $ast, int $offset): ?array
    {
        $visitor = new class($offset) extends NodeVisitorAbstract {
            /** @var list<ClassLike> */
            private array $classStack = [];

            /** @var list<FunctionLike> */
            private array $methodStack = [];

            public ?Name $found = null;

            /** @var list<ClassLike> */
            public array $foundClassStack = [];

            /** @var list<FunctionLike> */
            public array $foundMethodStack = [];

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof ClassLike) {
                    $this->classStack[] = $node;
                }
                if ($node instanceof FunctionLike) {
                    $this->methodStack[] = $node;
                }
                if (!$node instanceof Name) {
                    return null;
                }
                $start = $node->getStartFilePos();
                $end = $node->getEndFilePos();
                if ($start < 0 || $end < 0) {
                    return null;
                }
                // endFilePos points at the LAST byte of the node (inclusive),
                // so the half-open range is [start, end + 1).
                if ($this->offset < $start || $this->offset > $end) {
                    return null;
                }
                // Keep the SMALLEST matching node — replace any previous match
                // because nikic walks parents before children.
                $this->found = $node;
                $this->foundClassStack = $this->classStack;
                $this->foundMethodStack = $this->methodStack;
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof ClassLike) {
                    array_pop($this->classStack);
                }
                if ($node instanceof FunctionLike) {
                    array_pop($this->methodStack);
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        if ($visitor->found === null) {
            return null;
        }
        return [
            'name' => $visitor->found,
            'classScope' => $visitor->foundClassStack,
            'methodScope' => $visitor->foundMethodStack,
        ];
    }
}
