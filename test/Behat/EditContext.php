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

    /**
     * @Then every rename edit covers :text
     */
    public function everyRenameEditCovers(string $text): void
    {
        foreach ($this->renameDocumentChanges() as $change) {
            $uri = $change->textDocument->uri ?? '';
            foreach ($change->edits ?? [] as $edit) {
                $covered = $this->world->textForRange($uri, $edit->range);
                $this->world->assert(
                    $covered === $text,
                    sprintf('expected every rename edit to cover "%s", got "%s"', $text, $covered),
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

    /**
     * @Then a willRename edit inserts :text
     */
    public function aWillRenameEditInserts(string $text): void
    {
        foreach ($this->renameDocumentChanges() as $change) {
            foreach ($change->edits ?? [] as $edit) {
                if ($edit->newText === $text) {
                    return;
                }
            }
        }
        $this->world->fail(sprintf('expected a willRename edit inserting "%s"', $text));
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

    /**
     * @Then no code actions are offered
     */
    public function noCodeActionsAreOffered(): void
    {
        $actions = array_filter((array) $this->world->last(), static fn ($a): bool => $a instanceof CodeAction);
        $this->world->assert(
            $actions === [],
            sprintf('expected no code actions, got %d', count($actions)),
        );
    }

    /**
     * @Then the :title action has kind :kind
     */
    public function theActionHasKind(string $title, string $kind): void
    {
        $action = $this->findAction($title);
        $this->world->assert(
            $action->kind === $kind,
            sprintf('expected action "%s" to have kind "%s", got "%s"', $title, $kind, (string) $action->kind),
        );
    }

    /**
     * @Then the :title action inserts :text
     */
    public function theActionInserts(string $title, string $text): void
    {
        foreach ($this->actionEdits($this->findAction($title)) as $entry) {
            if (trim($entry['edit']->newText) === trim($text)) {
                return;
            }
        }
        $this->world->fail(sprintf('expected the "%s" action to insert "%s"', $title, $text));
    }

    /**
     * @Then the :title action removes the :text line
     */
    public function theActionRemovesTheLine(string $title, string $text): void
    {
        foreach ($this->actionEdits($this->findAction($title)) as $entry) {
            $covered = $this->world->textForRange($entry['uri'], $entry['edit']->range);
            if ($entry['edit']->newText === '' && trim($covered) === trim($text)) {
                return;
            }
        }
        $this->world->fail(sprintf('expected the "%s" action to delete the "%s" line', $title, $text));
    }

    /**
     * @Then the :title action replaces :old with :new
     */
    public function theActionReplaces(string $title, string $old, string $new): void
    {
        foreach ($this->actionEdits($this->findAction($title)) as $entry) {
            $covered = $this->world->textForRange($entry['uri'], $entry['edit']->range);
            if ($entry['edit']->newText === $new && $covered === $old) {
                return;
            }
        }
        $this->world->fail(sprintf('expected the "%s" action to replace "%s" with "%s"', $title, $old, $new));
    }

    private function findAction(string $title): CodeAction
    {
        foreach ((array) $this->world->last() as $action) {
            if ($action instanceof CodeAction && $action->title === $title) {
                return $action;
            }
        }
        $this->world->fail(sprintf('no code action titled "%s"', $title));
    }

    /** @return list<array{uri:string, edit:object}> */
    private function actionEdits(CodeAction $action): array
    {
        $out = [];
        foreach ($action->edit->documentChanges ?? [] as $change) {
            $uri = $change->textDocument->uri ?? '';
            foreach ($change->edits ?? [] as $edit) {
                $out[] = ['uri' => $uri, 'edit' => $edit];
            }
        }
        return $out;
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
     * @Then the resolved lens reads :title
     */
    public function theResolvedLensReads(string $title): void
    {
        $lens = $this->world->last();
        $this->world->assert($lens instanceof CodeLens && $lens->command !== null, 'expected a resolved code lens with a command');
        $this->world->assert(
            $lens->command->title === $title,
            sprintf('expected resolved lens to read "%s", got "%s"', $title, $lens->command->title),
        );
    }

    /**
     * @Then the resolved lens carries the reference locations
     */
    public function theResolvedLensCarriesTheReferenceLocations(): void
    {
        $lens = $this->world->last();
        $this->world->assert($lens instanceof CodeLens && $lens->command !== null, 'expected a resolved code lens with a command');
        $this->world->assert(
            $lens->command->command === 'editor.action.showReferences',
            sprintf('expected showReferences command, got "%s"', (string) $lens->command->command),
        );
        $args = $lens->command->arguments ?? [];
        $this->world->assert(
            isset($args[2]) && is_array($args[2]) && $args[2] !== [],
            'expected the resolved lens to carry a non-empty locations array',
        );
    }
}
