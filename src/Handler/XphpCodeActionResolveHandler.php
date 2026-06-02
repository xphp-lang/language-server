<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServerProtocol\CodeAction;

/**
 * `codeAction/resolve` handler.
 *
 * Round-trips a CodeAction the client received from
 * `textDocument/codeAction` and enriches it with the WorkspaceEdit
 * payload that actually performs the fix.  Lazy resolution keeps
 * the initial codeAction response small: building a full
 * WorkspaceEdit (which may require workspace-wide AST walks for
 * "import missing class" or "rename across files") is too
 * expensive to do for every potential action the editor's
 * lightbulb might render.
 *
 * Currently a no-op: every action {@see XphpCodeActionHandler}
 * returns already carries its WorkspaceEdit eagerly, so the client
 * applies the fix directly and never round-trips through
 * `codeAction/resolve`.  The handler stays registered as a hook
 * for any future fix expensive enough to emit a lightweight item
 * up-front (with a `data` payload identifying its kind + target)
 * and defer WorkspaceEdit construction to here.
 *
 * Available since IntelliJ Platform 2024.2.
 */
final class XphpCodeActionResolveHandler implements Handler
{
    public function methods(): array
    {
        return [
            'codeAction/resolve' => 'resolve',
        ];
    }

    /**
     * @return Promise<CodeAction>
     */
    public function resolve(CodeAction $action): Promise
    {
        // No-op -- XphpCodeActionHandler attaches every action's
        // WorkspaceEdit eagerly, so the client never round-trips
        // here.  Future fixes that defer their edit would add
        // per-kind dispatch on `$action->data` at this point.
        return new Success($action);
    }
}
