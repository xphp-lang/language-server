<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServerProtocol\DocumentHighlightKind;
use XPHP\Transpiler\Monomorphize\ByteOffsetMap;

/**
 * Classifies each document-highlight occurrence as read, write, or text.
 *
 * `textDocument/documentHighlight` returns a flat list of ranges; the LSP
 * `kind` field lets a client paint a symbol's definition / assignments
 * (write) differently from its uses (read). We compute it with a parent-aware
 * AST walk:
 *
 *   - WRITE: a declaration name (class / interface / enum / trait / method /
 *     function / property), or an lvalue occurrence -- a variable or property
 *     on the left of an assignment, a `foreach (... as $x)` target, or an
 *     increment / decrement target.
 *   - READ: every other occurrence (a use site).
 *
 * AST node offsets index into the xphp-STRIPPED source; the supplied
 * {@see ByteOffsetMap} maps them back to the ORIGINAL source so they line up
 * with the location offsets the caller computes from the document text.
 */
final class DocumentHighlightKindResolver
{
    /**
     * @param list<Node\Stmt> $ast
     * @param list<int>       $targetOriginalOffsets start byte offset (original source) of each occurrence
     * @return array<int, int> original-offset -> DocumentHighlightKind
     */
    public function resolve(array $ast, ByteOffsetMap $map, array $targetOriginalOffsets): array
    {
        if ($ast === [] || $targetOriginalOffsets === []) {
            return [];
        }
        $result = [];
        $visitor = new class($map, array_fill_keys($targetOriginalOffsets, true), $result) extends NodeVisitorAbstract {
            /** @var list<Node> */
            private array $stack = [];

            /**
             * @param array<int, true>  $wanted
             * @param array<int, int>   $result
             */
            public function __construct(
                private readonly ByteOffsetMap $map,
                private readonly array $wanted,
                public array &$result,
            ) {
            }

            public function enterNode(Node $node): null
            {
                $parent = $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
                $this->classify($node, $parent);
                $this->stack[] = $node;
                return null;
            }

            public function leaveNode(Node $node): null
            {
                array_pop($this->stack);
                return null;
            }

            private function mark(int $strippedStart, int $kind): void
            {
                if ($strippedStart < 0) {
                    return;
                }
                $original = $this->map->toOriginal($strippedStart);
                if (isset($this->wanted[$original])) {
                    $this->result[$original] = $kind;
                }
            }

            private function classify(Node $node, ?Node $parent): void
            {
                // Declaration names -- the symbol is defined ("written") here.
                if ($node instanceof ClassLike && $node->name !== null) {
                    $this->mark($node->name->getStartFilePos(), DocumentHighlightKind::WRITE);
                    return;
                }
                if ($node instanceof ClassMethod || $node instanceof Function_) {
                    $this->mark($node->name->getStartFilePos(), DocumentHighlightKind::WRITE);
                    return;
                }
                if ($node instanceof PropertyItem) {
                    $this->mark($node->name->getStartFilePos(), DocumentHighlightKind::WRITE);
                    return;
                }

                // Variables: lvalue -> write, otherwise read.
                if ($node instanceof Variable) {
                    $this->mark(
                        $node->getStartFilePos(),
                        self::isVariableWrite($node, $parent) ? DocumentHighlightKind::WRITE : DocumentHighlightKind::READ,
                    );
                    return;
                }

                // Property / static-property fetches: write when on an
                // assignment's left-hand side.
                if ($node instanceof PropertyFetch && $node->name instanceof Identifier) {
                    $this->mark(
                        $node->name->getStartFilePos(),
                        self::isAssignTarget($node, $parent) ? DocumentHighlightKind::WRITE : DocumentHighlightKind::READ,
                    );
                    return;
                }
                if ($node instanceof StaticPropertyFetch && $node->name instanceof VarLikeIdentifier) {
                    $this->mark(
                        $node->name->getStartFilePos(),
                        self::isAssignTarget($node, $parent) ? DocumentHighlightKind::WRITE : DocumentHighlightKind::READ,
                    );
                    return;
                }

                // Bare class references (`new User`, type names, `use App\User`).
                if ($node instanceof Name) {
                    $this->mark($node->getStartFilePos(), DocumentHighlightKind::READ);
                    return;
                }

                // Method-call names (`$x->foo()`, `Foo::bar()`).
                if ($node instanceof Identifier
                    && ($parent instanceof MethodCall || $parent instanceof NullsafeMethodCall || $parent instanceof StaticCall)
                    && $parent->name === $node
                ) {
                    $this->mark($node->getStartFilePos(), DocumentHighlightKind::READ);
                }
            }

            private static function isVariableWrite(Variable $node, ?Node $parent): bool
            {
                if ($parent instanceof Assign || $parent instanceof AssignRef || $parent instanceof AssignOp) {
                    return $parent->var === $node;
                }
                if ($parent instanceof Foreach_) {
                    return $parent->valueVar === $node || $parent->keyVar === $node;
                }
                if ($parent instanceof PreInc || $parent instanceof PostInc
                    || $parent instanceof PreDec || $parent instanceof PostDec
                ) {
                    return $parent->var === $node;
                }
                return false;
            }

            private static function isAssignTarget(Node $node, ?Node $parent): bool
            {
                return ($parent instanceof Assign || $parent instanceof AssignRef || $parent instanceof AssignOp)
                    && $parent->var === $node;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->result;
    }
}
