<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Behat\Context\Context;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\CompletionList;
use Phpactor\LanguageServerProtocol\CompletionParams;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;

/**
 * Steps for the Find theme: completion and completionItem/resolve.
 */
final class FindContext implements Context
{
    public function __construct(private readonly World $world)
    {
    }

    /**
     * @When I request completion after :needle at line :line of :path
     */
    public function iRequestCompletionAfterAtLineOf(string $needle, int $line, string $path): void
    {
        $start = $this->world->positionOfNeedle($path, $line, $needle);
        $cursor = new Position($start->line, $start->character + strlen($needle));
        $params = new CompletionParams(new TextDocumentIdentifier($path), $cursor);
        $this->world->request('textDocument/completion', $params);
    }

    /**
     * @Then a completion item labeled :label is offered
     */
    public function aCompletionItemLabeledIsOffered(string $label): void
    {
        $labels = $this->completionLabels();
        $this->world->assert(
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
        $this->world->assert(
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
                $this->world->assert(
                    $item->insertText === $text,
                    sprintf('expected "%s" to insert "%s", got "%s"', $label, $text, (string) $item->insertText),
                );
                return;
            }
        }
        $this->world->fail(sprintf('no completion item labeled "%s"', $label));
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
        $this->world->request('completionItem/resolve', $item);
    }

    /**
     * @Then the resolved item documentation contains :text
     */
    public function theResolvedItemDocumentationContains(string $text): void
    {
        $item = $this->world->last();
        $this->world->assert($item instanceof CompletionItem, 'expected a CompletionItem response, got ' . get_debug_type($item));
        $doc = $item->documentation;
        $value = $doc instanceof MarkupContent ? $doc->value : (is_string($doc) ? $doc : '');
        $this->world->assert(
            str_contains($value, $text),
            sprintf('expected resolved documentation to contain "%s", got: %s', $text, $value === '' ? '<empty>' : $value),
        );
    }

    /** @return list<CompletionItem> */
    private function completionItems(): array
    {
        $response = $this->world->last();
        $items = $response instanceof CompletionList ? $response->items : $response;
        $this->world->assert(is_array($items), 'expected a completion list response');
        return $items;
    }

    /** @return list<string> */
    private function completionLabels(): array
    {
        return array_map(static fn (CompletionItem $i): string => $i->label, $this->completionItems());
    }
}
