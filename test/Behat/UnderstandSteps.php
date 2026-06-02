<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\InlayHint;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\SemanticTokens;
use Phpactor\LanguageServerProtocol\SignatureHelp;
use Phpactor\LanguageServerProtocol\SignatureHelpParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use XPHP\Lsp\Handler\SemanticTokens\TokenLegend;

use function Amp\Promise\wait;

/**
 * Steps for the Understand theme: hover, signature help, inlay hints, folding
 * ranges, and semantic tokens.
 */
trait UnderstandSteps
{
    /**
     * @When I request signature help after :needle at line :line of :path
     */
    public function iRequestSignatureHelpAfterAtLineOf(string $needle, int $line, string $path): void
    {
        $start = $this->positionOfNeedle($path, $line, $needle);
        $cursor = new Position($start->line, $start->character + strlen($needle));
        $params = new SignatureHelpParams(new TextDocumentIdentifier($path), $cursor);
        $this->lastResponse = wait($this->handler('signatureHelp')->signatureHelp($params));
    }

    /**
     * @Then the active signature label contains :text
     */
    public function theActiveSignatureLabelContains(string $text): void
    {
        $help = $this->lastResponse;
        $this->assert($help instanceof SignatureHelp, 'expected a SignatureHelp response, got ' . get_debug_type($help));
        $index = $help->activeSignature ?? 0;
        $signature = $help->signatures[$index] ?? $help->signatures[0] ?? null;
        $this->assert($signature !== null, 'expected at least one signature');
        $this->assert(
            str_contains($signature->label, $text),
            sprintf('expected active signature label to contain "%s", got "%s"', $text, $signature->label),
        );
    }

    /**
     * @Then the active parameter is :index
     */
    public function theActiveParameterIs(int $index): void
    {
        $help = $this->lastResponse;
        $this->assert($help instanceof SignatureHelp, 'expected a SignatureHelp response, got ' . get_debug_type($help));
        $this->assert(
            $help->activeParameter === $index,
            sprintf('expected active parameter %d, got %s', $index, var_export($help->activeParameter, true)),
        );
    }

    /**
     * @Then the hover contents contain :text
     */
    public function theHoverContentsContain(string $text): void
    {
        $this->assertHoverContains($text);
    }

    /**
     * @Then the semantic tokens are non-empty
     */
    public function theSemanticTokensAreNonEmpty(): void
    {
        $tokens = $this->lastResponse;
        $this->assert($tokens instanceof SemanticTokens, 'expected a SemanticTokens response, got ' . get_debug_type($tokens));
        $this->assert($tokens->data !== [], 'expected a non-empty token stream');
        $this->assert(count($tokens->data) % 5 === 0, 'expected the token stream length to be a multiple of 5');
    }

    /**
     * @Then the semantic tokens include a :type token
     */
    public function theSemanticTokensIncludeAToken(string $type): void
    {
        $tokens = $this->lastResponse;
        $this->assert($tokens instanceof SemanticTokens, 'expected a SemanticTokens response, got ' . get_debug_type($tokens));
        $typeIndex = array_search($type, TokenLegend::TOKEN_TYPES, true);
        $this->assert($typeIndex !== false, "unknown token type: {$type}");

        // Packed as 5 ints per token; the type index is the 4th of each tuple.
        for ($i = 0; $i + 4 < count($tokens->data); $i += 5) {
            if ($tokens->data[$i + 3] === $typeIndex) {
                return;
            }
        }
        $this->fail(sprintf('expected a "%s" (index %d) token in the stream', $type, $typeIndex));
    }

    /**
     * @Then the response contains :count folding ranges
     */
    public function theResponseContainsFoldingRanges(int $count): void
    {
        $this->assert(is_array($this->lastResponse), 'expected a folding-range list response');
        $this->assert(
            count($this->lastResponse) === $count,
            sprintf('expected %d folding ranges, got %d', $count, count($this->lastResponse)),
        );
    }

    /**
     * @Then a folding range spans lines :start to :end
     */
    public function aFoldingRangeSpansLinesTo(int $start, int $end): void
    {
        $seen = [];
        foreach ((array) $this->lastResponse as $range) {
            $seen[] = sprintf('%d-%d', $range->startLine, $range->endLine);
            if ($range->startLine === $start && $range->endLine === $end) {
                return;
            }
        }
        $this->fail(sprintf('expected a folding range %d-%d; got: [%s]', $start, $end, implode(', ', $seen)));
    }

    /**
     * @Then there is no hover
     */
    public function thereIsNoHover(): void
    {
        $this->assert(
            $this->lastResponse === null,
            'expected no hover, got ' . get_debug_type($this->lastResponse),
        );
    }

    /**
     * @Then an inlay hint :label is rendered after :var on line :line
     */
    public function anInlayHintIsRenderedAfterOnLine(string $label, string $var, int $line): void
    {
        $hints = $this->lastResponse;
        $this->assert(is_array($hints), 'expected an inlay-hint list response');

        $seen = [];
        foreach ($hints as $hint) {
            if (!$hint instanceof InlayHint) {
                continue;
            }
            $hintLabel = is_string($hint->label) ? $hint->label : '';
            $seen[] = sprintf('%s@L%d', $hintLabel, $hint->position->line);
            if ($hintLabel === $label && $hint->position->line === $line) {
                return;
            }
        }

        $this->fail(sprintf(
            'no inlay hint "%s" on line %d (after "%s"); got: [%s]',
            $label,
            $line,
            $var,
            implode(', ', $seen) ?: '<none>',
        ));
    }

    private function assertHoverContains(string $needle): void
    {
        $hover = $this->lastResponse;
        $this->assert($hover instanceof Hover, 'expected a Hover response, got ' . get_debug_type($hover));
        $contents = $hover->contents;
        $text = $contents instanceof MarkupContent ? $contents->value : (is_string($contents) ? $contents : '');
        $this->assert(
            str_contains($text, $needle),
            sprintf('expected hover contents to contain "%s", got: %s', $needle, $text === '' ? '<empty>' : $text),
        );
    }
}
