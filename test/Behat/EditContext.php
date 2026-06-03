<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Behat\Context\Context;
use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\CodeActionContext;
use Phpactor\LanguageServerProtocol\CodeActionParams;
use Phpactor\LanguageServerProtocol\CodeLens;
use Phpactor\LanguageServerProtocol\CodeLensParams;
use Phpactor\LanguageServerProtocol\Diagnostic;
use Phpactor\LanguageServerProtocol\FileRename;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\RenameFilesParams;
use Phpactor\LanguageServerProtocol\RenameParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use XPHP\Lsp\Analyzer\DiagnosticCode;

/**
 * Steps for the Edit theme: rename, code actions, code lens, and
 * workspace/willRenameFiles.
 */
final class EditContext implements Context
{
    public function __construct(private readonly World $world)
    {
    }

    // ---- rename ------------------------------------------------------------

    /**
     * @When I rename :needle at line :line of :path to :newName
     */
    public function iRenameAtLineOfTo(string $needle, int $line, string $path, string $newName): void
    {
        $params = new RenameParams(
            new TextDocumentIdentifier($path),
            $this->world->positionOfNeedle($path, $line, $needle),
            $newName,
        );
        $this->world->request('textDocument/rename', $params);
    }

    /**
     * @Then the rename touches :count files
     */
    public function theRenameTouchesFiles(int $count): void
    {
        $changes = $this->renameDocumentChanges();
        $this->world->assert(
            count($changes) === $count,
            sprintf('expected the rename to touch %d files, got %d', $count, count($changes)),
        );
    }

    /**
     * @Then the rename applies :count edits
     */
    public function theRenameAppliesEdits(int $count): void
    {
        $total = 0;
        foreach ($this->renameDocumentChanges() as $change) {
            $total += count($change->edits ?? []);
        }
        $this->world->assert($total === $count, sprintf('expected %d rename edits, got %d', $count, $total));
    }

    /**
     * @Then every rename edit inserts :text
     */
    public function everyRenameEditInserts(string $text): void
    {
        foreach ($this->renameDocumentChanges() as $change) {
            foreach ($change->edits ?? [] as $edit) {
                $this->world->assert(
                    $edit->newText === $text,
                    sprintf('expected every rename edit to insert "%s", saw "%s"', $text, $edit->newText),
                );
            }
        }
    }

    /** @return list<object> TextDocumentEdit entries */
    private function renameDocumentChanges(): array
    {
        $edit = $this->world->last();
        $this->world->assert(is_object($edit), 'expected a WorkspaceEdit response, got ' . get_debug_type($edit));
        $changes = $edit->documentChanges ?? null;
        $this->world->assert(is_array($changes), 'expected the WorkspaceEdit to carry documentChanges');
        return $changes;
    }

    // ---- workspace/willRenameFiles -----------------------------------------

    /**
     * @When I rename the file :oldUri to :newUri
     */
    public function iRenameTheFileTo(string $oldUri, string $newUri): void
    {
        $params = new RenameFilesParams([new FileRename($oldUri, $newUri)]);
        $this->world->request('workspace/willRenameFiles', $params);
    }

    // ---- code actions ------------------------------------------------------

    /**
     * @When I request code actions on :needle at line :line of :path
     */
    public function iRequestCodeActionsOnAtLineOf(string $needle, int $line, string $path): void
    {
        $pos = $this->world->positionOfNeedle($path, $line, $needle);
        $params = new CodeActionParams(
            new TextDocumentIdentifier($path),
            new Range($pos, $pos),
            new CodeActionContext([]),
        );
        $this->world->request('textDocument/codeAction', $params);
    }

    /**
     * @When I request code actions for an undefined-name diagnostic on :needle at line :line of :path
     */
    public function iRequestCodeActionsForADiagnosticOnAtLineOf(string $needle, int $line, string $path): void
    {
        $start = $this->world->positionOfNeedle($path, $line, $needle);
        $end = new Position($start->line, $start->character + strlen($needle));
        $range = new Range($start, $end);
        $diagnostic = new Diagnostic($range, "Undefined: {$needle}", null, DiagnosticCode::UndefinedName->value);
        $params = new CodeActionParams(
            new TextDocumentIdentifier($path),
            $range,
            new CodeActionContext([$diagnostic]),
        );
        $this->world->request('textDocument/codeAction', $params);
    }

    /**
     * @Then a code action titled :title is offered
     */
    public function aCodeActionTitledIsOffered(string $title): void
    {
        $titles = [];
        foreach ((array) $this->world->last() as $action) {
            if ($action instanceof CodeAction) {
                $titles[] = $action->title;
                if ($action->title === $title) {
                    return;
                }
            }
        }
        $this->world->fail(sprintf('expected a code action titled "%s"; got: [%s]', $title, implode(', ', $titles)));
    }

    // ---- code lens ---------------------------------------------------------

    /**
     * @When I request code lenses for :path
     */
    public function iRequestCodeLensesFor(string $path): void
    {
        $params = new CodeLensParams(new TextDocumentIdentifier($path));
        $this->world->request('textDocument/codeLens', $params);
    }

    /**
     * @When I resolve the first code lens
     */
    public function iResolveTheFirstCodeLens(): void
    {
        $lenses = $this->world->last();
        $this->world->assert(is_array($lenses) && isset($lenses[0]) && $lenses[0] instanceof CodeLens, 'expected at least one code lens to resolve');
        $this->world->request('codeLens/resolve', $lenses[0]);
    }

    /**
     * @Then a code lens titled :title is offered
     */
    public function aCodeLensTitledIsOffered(string $title): void
    {
        $titles = [];
        foreach ((array) $this->world->last() as $lens) {
            if ($lens instanceof CodeLens && $lens->command !== null) {
                $titles[] = $lens->command->title;
                if ($lens->command->title === $title) {
                    return;
                }
            }
        }
        $this->world->fail(sprintf('expected a code lens titled "%s"; got: [%s]', $title, implode(', ', $titles)));
    }

    /**
     * @Then the resolved lens mentions a usage count
     */
    public function theResolvedLensMentionsAUsageCount(): void
    {
        $lens = $this->world->last();
        $this->world->assert($lens instanceof CodeLens && $lens->command !== null, 'expected a resolved code lens with a command');
        $title = $lens->command->title;
        $this->world->assert(
            preg_match('/^\d+ usages?$/', $title) === 1,
            sprintf('expected resolved lens to read "<n> usage(s)", got "%s"', $title),
        );
    }
}
