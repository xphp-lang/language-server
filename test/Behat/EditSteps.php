<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\CodeActionContext;
use Phpactor\LanguageServerProtocol\CodeActionParams;
use Phpactor\LanguageServerProtocol\CodeLens;
use Phpactor\LanguageServerProtocol\CodeLensParams;
use Phpactor\LanguageServerProtocol\Diagnostic;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\RenameParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use XPHP\Lsp\Analyzer\DiagnosticCode;

use function Amp\Promise\wait;

/**
 * Steps for the Edit theme: rename, code actions, code lens, and
 * workspace/willRenameFiles.
 */
trait EditSteps
{
    // ---- rename ------------------------------------------------------------

    /**
     * @When I rename :needle at line :line of :path to :newName
     */
    public function iRenameAtLineOfTo(string $needle, int $line, string $path, string $newName): void
    {
        $params = new RenameParams(
            new TextDocumentIdentifier($path),
            $this->positionOfNeedle($path, $line, $needle),
            $newName,
        );
        $this->lastResponse = wait($this->handler('rename')->rename($params));
    }

    /**
     * @Then the rename touches :count files
     */
    public function theRenameTouchesFiles(int $count): void
    {
        $changes = $this->renameDocumentChanges();
        $this->assert(
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
        $this->assert($total === $count, sprintf('expected %d rename edits, got %d', $count, $total));
    }

    /**
     * @Then every rename edit inserts :text
     */
    public function everyRenameEditInserts(string $text): void
    {
        foreach ($this->renameDocumentChanges() as $change) {
            foreach ($change->edits ?? [] as $edit) {
                $this->assert(
                    $edit->newText === $text,
                    sprintf('expected every rename edit to insert "%s", saw "%s"', $text, $edit->newText),
                );
            }
        }
    }

    /** @return list<object> TextDocumentEdit entries */
    private function renameDocumentChanges(): array
    {
        $edit = $this->lastResponse;
        $this->assert(is_object($edit), 'expected a WorkspaceEdit response, got ' . get_debug_type($edit));
        $changes = $edit->documentChanges ?? null;
        $this->assert(is_array($changes), 'expected the WorkspaceEdit to carry documentChanges');
        return $changes;
    }

    // ---- code actions ------------------------------------------------------

    /**
     * @When I request code actions on :needle at line :line of :path
     */
    public function iRequestCodeActionsOnAtLineOf(string $needle, int $line, string $path): void
    {
        $pos = $this->positionOfNeedle($path, $line, $needle);
        $params = new CodeActionParams(
            new TextDocumentIdentifier($path),
            new Range($pos, $pos),
            new CodeActionContext([]),
        );
        $this->lastResponse = wait($this->handler('codeAction')->codeAction($params));
    }

    /**
     * @When I request code actions for an undefined-name diagnostic on :needle at line :line of :path
     */
    public function iRequestCodeActionsForADiagnosticOnAtLineOf(string $needle, int $line, string $path): void
    {
        $start = $this->positionOfNeedle($path, $line, $needle);
        $end = new Position($start->line, $start->character + strlen($needle));
        $range = new Range($start, $end);
        $diagnostic = new Diagnostic($range, "Undefined: {$needle}", null, DiagnosticCode::UndefinedName->value);
        $params = new CodeActionParams(
            new TextDocumentIdentifier($path),
            $range,
            new CodeActionContext([$diagnostic]),
        );
        $this->lastResponse = wait($this->handler('codeAction')->codeAction($params));
    }

    /**
     * @Then a code action titled :title is offered
     */
    public function aCodeActionTitledIsOffered(string $title): void
    {
        $titles = [];
        foreach ((array) $this->lastResponse as $action) {
            if ($action instanceof CodeAction) {
                $titles[] = $action->title;
                if ($action->title === $title) {
                    return;
                }
            }
        }
        $this->fail(sprintf('expected a code action titled "%s"; got: [%s]', $title, implode(', ', $titles)));
    }

    // ---- code lens ---------------------------------------------------------

    /**
     * @When I request code lenses for :path
     */
    public function iRequestCodeLensesFor(string $path): void
    {
        $params = new CodeLensParams(new TextDocumentIdentifier($path));
        $this->lastResponse = wait($this->handler('codeLens')->codeLens($params));
    }

    /**
     * @When I resolve the first code lens
     */
    public function iResolveTheFirstCodeLens(): void
    {
        $lenses = $this->lastResponse;
        $this->assert(is_array($lenses) && isset($lenses[0]) && $lenses[0] instanceof CodeLens, 'expected at least one code lens to resolve');
        $this->lastResponse = wait($this->handler('codeLens')->resolve($lenses[0]));
    }

    /**
     * @Then a code lens titled :title is offered
     */
    public function aCodeLensTitledIsOffered(string $title): void
    {
        $titles = [];
        foreach ((array) $this->lastResponse as $lens) {
            if ($lens instanceof CodeLens && $lens->command !== null) {
                $titles[] = $lens->command->title;
                if ($lens->command->title === $title) {
                    return;
                }
            }
        }
        $this->fail(sprintf('expected a code lens titled "%s"; got: [%s]', $title, implode(', ', $titles)));
    }

    /**
     * @Then the resolved lens is titled :title
     */
    public function theResolvedLensIsTitled(string $title): void
    {
        $lens = $this->lastResponse;
        $this->assert($lens instanceof CodeLens && $lens->command !== null, 'expected a resolved code lens with a command');
        $this->assert(
            $lens->command->title === $title,
            sprintf('expected resolved lens titled "%s", got "%s"', $title, $lens->command->title),
        );
    }
}
