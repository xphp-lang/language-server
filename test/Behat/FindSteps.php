<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\CompletionList;
use Phpactor\LanguageServerProtocol\CompletionParams;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;

use function Amp\Promise\wait;

/**
 * Steps for the Find theme: completion and completionItem/resolve.
 */
trait FindSteps
{
    /**
     * @When I request completion after :needle at line :line of :path
     */
    public function iRequestCompletionAfterAtLineOf(string $needle, int $line, string $path): void
    {
        $start = $this->positionOfNeedle($path, $line, $needle);
        $cursor = new Position($start->line, $start->character + strlen($needle));
        $params = new CompletionParams(new TextDocumentIdentifier($path), $cursor);
        $this->lastResponse = wait($this->handler('completion')->complete($params));
    }

    /**
     * @Then a completion item labeled :label is offered
     */
    public function aCompletionItemLabeledIsOffered(string $label): void
    {
        $labels = $this->completionLabels();
        $this->assert(
            in_array($label, $labels, true),
            sprintf('expected a completion item labeled "%s"; got: [%s]', $label, implode(', ', $labels)),
        );
    }

    /**
     * @Then no completion item labeled :label is offered
     */
    public function noCompletionItemLabeledIsOffered(string $label): void
    {
        $labels = $this->completionLabels();
        $this->assert(
            !in_array($label, $labels, true),
            sprintf('expected no completion item labeled "%s"; got: [%s]', $label, implode(', ', $labels)),
        );
    }

    /**
     * @Then the completion item :label inserts :text
     */
    public function theCompletionItemInserts(string $label, string $text): void
    {
        foreach ($this->completionItems() as $item) {
            if ($item->label === $label) {
                $this->assert(
                    $item->insertText === $text,
                    sprintf('expected "%s" to insert "%s", got "%s"', $label, $text, (string) $item->insertText),
                );
                return;
            }
        }
        $this->fail(sprintf('no completion item labeled "%s"', $label));
    }

    /**
     * @When I resolve a class completion item for :fqn
     */
    public function iResolveAClassCompletionItemFor(string $fqn): void
    {
        $shortName = substr((string) strrchr($fqn, '\\'), 1) ?: $fqn;
        $item = new CompletionItem(
            label: $shortName,
            kind: CompletionItemKind::CLASS_,
            data: ['kind' => 'class', 'fqn' => $fqn],
        );
        $this->lastResponse = wait($this->handler('completionResolve')->resolve($item));
    }

    /**
     * @Then the resolved item documentation contains :text
     */
    public function theResolvedItemDocumentationContains(string $text): void
    {
        $item = $this->lastResponse;
        $this->assert($item instanceof CompletionItem, 'expected a CompletionItem response, got ' . get_debug_type($item));
        $doc = $item->documentation;
        $value = $doc instanceof MarkupContent ? $doc->value : (is_string($doc) ? $doc : '');
        $this->assert(
            str_contains($value, $text),
            sprintf('expected resolved documentation to contain "%s", got: %s', $text, $value === '' ? '<empty>' : $value),
        );
    }

    /** @return list<CompletionItem> */
    private function completionItems(): array
    {
        $response = $this->lastResponse;
        $items = $response instanceof CompletionList ? $response->items : $response;
        $this->assert(is_array($items), 'expected a completion list response');
        return $items;
    }

    /** @return list<string> */
    private function completionLabels(): array
    {
        return array_map(static fn (CompletionItem $i): string => $i->label, $this->completionItems());
    }
}
