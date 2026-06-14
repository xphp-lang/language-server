<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Behat\Context\Context;
use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\InlayHint;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\SemanticTokens;
use Phpactor\LanguageServerProtocol\SignatureHelp;
use Phpactor\LanguageServerProtocol\SignatureHelpParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use XPHP\Lsp\Handler\SemanticTokens\TokenLegend;
use XPHP\Lsp\PositionMap;

/**
 * Steps for the Understand theme: hover, signature help, inlay hints, folding
 * ranges, and semantic tokens.
 */
final class UnderstandContext implements Context
{
    public function __construct(private readonly World $world)
    {
    }

    /**
     * @When I request signature help after :needle at line :line of :path
     */
    public function iRequestSignatureHelpAfterAtLineOf(string $needle, int $line, string $path): void
    {
        $start = $this->world->positionOfNeedle($path, $line, $needle);
        $cursor = new Position($start->line, $start->character + strlen($needle));
        $params = new SignatureHelpParams(new TextDocumentIdentifier($path), $cursor);
        $this->world->request('textDocument/signatureHelp', $params);
    }

    /**
     * @Then the active signature label contains :text
     */
    public function theActiveSignatureLabelContains(string $text): void
    {
        $help = $this->world->last();
        $this->world->assert($help instanceof SignatureHelp, 'expected a SignatureHelp response, got ' . get_debug_type($help));
        $index = $help->activeSignature ?? 0;
        $signature = $help->signatures[$index] ?? $help->signatures[0] ?? null;
        $this->world->assert($signature !== null, 'expected at least one signature');
        $this->world->assert(
            str_contains($signature->label, $text),
            sprintf('expected active signature label to contain "%s", got "%s"', $text, $signature->label),
        );
    }

    /**
     * @Then the active signature label is :label
     */
    public function theActiveSignatureLabelIs(string $label): void
    {
        $help = $this->world->last();
        $this->world->assert($help instanceof SignatureHelp, 'expected a SignatureHelp response, got ' . get_debug_type($help));
        $index = $help->activeSignature ?? 0;
        $signature = $help->signatures[$index] ?? $help->signatures[0] ?? null;
        $this->world->assert($signature !== null, 'expected at least one signature');
        $this->world->assert(
            $signature->label === $label,
            sprintf('expected active signature label "%s", got "%s"', $label, $signature->label),
        );
    }

    /**
     * @Then the active parameter is :index
     */
    public function theActiveParameterIs(int $index): void
    {
        $help = $this->world->last();
        $this->world->assert($help instanceof SignatureHelp, 'expected a SignatureHelp response, got ' . get_debug_type($help));
        $this->world->assert(
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
        $tokens = $this->world->last();
        $this->world->assert($tokens instanceof SemanticTokens, 'expected a SemanticTokens response, got ' . get_debug_type($tokens));
        $this->world->assert($tokens->data !== [], 'expected a non-empty token stream');
        $this->world->assert(count($tokens->data) % 5 === 0, 'expected the token stream length to be a multiple of 5');
    }

    /**
     * @Then the semantic tokens include a :type token
     */
    public function theSemanticTokensIncludeAToken(string $type): void
    {
        $tokens = $this->world->last();
        $this->world->assert($tokens instanceof SemanticTokens, 'expected a SemanticTokens response, got ' . get_debug_type($tokens));
        $typeIndex = array_search($type, TokenLegend::TOKEN_TYPES, true);
        $this->world->assert($typeIndex !== false, "unknown token type: {$type}");

        // Packed as 5 ints per token; the type index is the 4th of each tuple.
        for ($i = 0; $i + 4 < count($tokens->data); $i += 5) {
            if ($tokens->data[$i + 3] === $typeIndex) {
                return;
            }
        }
        $this->world->fail(sprintf('expected a "%s" (index %d) token in the stream', $type, $typeIndex));
    }

    /**
     * @Then the response contains :count folding ranges
     */
    public function theResponseContainsFoldingRanges(int $count): void
    {
        $response = $this->world->last();
        $this->world->assert(is_array($response), 'expected a folding-range list response');
        $this->world->assert(
            count($response) === $count,
            sprintf('expected %d folding ranges, got %d', $count, count($response)),
        );
    }

    /**
     * @Then a folding range spans lines :start to :end
     */
    public function aFoldingRangeSpansLinesTo(int $start, int $end): void
    {
        $seen = [];
        foreach ((array) $this->world->last() as $range) {
            $seen[] = sprintf('%d-%d', $range->startLine, $range->endLine);
            if ($range->startLine === $start && $range->endLine === $end) {
                return;
            }
        }
        $this->world->fail(sprintf('expected a folding range %d-%d; got: [%s]', $start, $end, implode(', ', $seen)));
    }

    /**
     * @Then a folding range of kind :kind spans :start to :end
     */
    public function aFoldingRangeOfKindSpans(string $kind, int $start, int $end): void
    {
        $seen = [];
        foreach ((array) $this->world->last() as $range) {
            $seen[] = sprintf('%s %d-%d', (string) ($range->kind ?? '?'), $range->startLine, $range->endLine);
            if (($range->kind ?? null) === $kind && $range->startLine === $start && $range->endLine === $end) {
                return;
            }
        }
        $this->world->fail(sprintf('expected a %s folding range %d-%d; got: [%s]', $kind, $start, $end, implode(', ', $seen)));
    }

    /**
     * @Then a :type token covers :text in :path
     */
    public function aTokenCoversIn(string $type, string $text, string $path): void
    {
        $tokens = $this->world->last();
        $this->world->assert($tokens instanceof SemanticTokens, 'expected a SemanticTokens response, got ' . get_debug_type($tokens));
        foreach ($this->world->decodeSemanticTokens($tokens, $path) as $token) {
            if ($token['type'] === $type && $token['text'] === $text) {
                return;
            }
        }
        $this->world->fail(sprintf('expected a %s token covering "%s" in %s', $type, $text, $path));
    }

    /**
     * @Then there is no hover
     */
    public function thereIsNoHover(): void
    {
        $this->world->assert(
            $this->world->last() === null,
            'expected no hover, got ' . get_debug_type($this->world->last()),
        );
    }

    /**
     * @Then exactly :count inlay hint is rendered
     * @Then exactly :count inlay hints are rendered
     */
    public function exactlyInlayHintsAreRendered(int $count): void
    {
        $hints = $this->world->last();
        $this->world->assert(is_array($hints), 'expected an inlay-hint list response');
        $actual = count(array_filter($hints, static fn ($h): bool => $h instanceof InlayHint));
        $this->world->assert(
            $actual === $count,
            sprintf('expected exactly %d inlay hints, got %d', $count, $actual),
        );
    }

    /**
     * @Then an inlay hint :label is rendered after :var on line :line of :path
     */
    public function anInlayHintIsRenderedAfterOnLineOf(string $label, string $var, int $line, string $path): void
    {
        $expectedChar = $this->world->positionOfNeedle($path, $line, $var)->character + strlen($var);
        $hints = $this->world->last();
        $this->world->assert(is_array($hints), 'expected an inlay-hint list response');

        $seen = [];
        foreach ($hints as $hint) {
            if (!$hint instanceof InlayHint) {
                continue;
            }
            $hintLabel = is_string($hint->label) ? $hint->label : '';
            $seen[] = sprintf('%s@L%d:%d', $hintLabel, $hint->position->line, $hint->position->character);
            if ($hintLabel === $label && $hint->position->line === $line && $hint->position->character === $expectedChar) {
                return;
            }
        }

        $this->world->fail(sprintf(
            'no inlay hint "%s" just after "%s" (line %d, char %d); got: [%s]',
            $label,
            $var,
            $line,
            $expectedChar,
            implode(', ', $seen) ?: '<none>',
        ));
    }

    /**
     * @Then an inlay hint :label is rendered after :var on line :line
     */
    public function anInlayHintIsRenderedAfterOnLine(string $label, string $var, int $line): void
    {
        $hints = $this->world->last();
        $this->world->assert(is_array($hints), 'expected an inlay-hint list response');

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

        $this->world->fail(sprintf(
            'no inlay hint "%s" on line %d (after "%s"); got: [%s]',
            $label,
            $line,
            $var,
            implode(', ', $seen) ?: '<none>',
        ));
    }

    /**
     * @Then every semantic token is within the bounds of :path
     */
    public function everySemanticTokenIsWithinTheBoundsOf(string $path): void
    {
        $tokens = $this->world->last();
        $this->world->assert($tokens instanceof SemanticTokens, 'expected a SemanticTokens response, got ' . get_debug_type($tokens));
        foreach ($this->world->decodeSemanticTokens($tokens, $path) as $i => $token) {
            // The decoder yields the token's text; its UTF-16 length is what the
            // LSP wire `length` encodes, so measure it the same way.
            $len = PositionMap::lengthInUtf16($token['text']);
            $range = new Range(
                new Position($token['line'], $token['char']),
                new Position($token['line'], $token['char'] + $len),
            );
            $this->world->assert(
                $this->world->rangeWithinDocument($path, $range),
                sprintf('semantic token #%d out of document bounds at %d:%d (+%d)', $i, $token['line'], $token['char'], $len),
            );
        }
    }

    /**
     * @Then every folding range is within the bounds of :path
     */
    public function everyFoldingRangeIsWithinTheBoundsOf(string $path): void
    {
        $ranges = $this->world->last();
        $this->world->assert(is_array($ranges) && $ranges !== [], 'expected a non-empty folding-range list');
        foreach ($ranges as $i => $range) {
            // Folding ranges carry only line numbers; check the line span
            // (column 0 is always valid) against the document bounds.
            $check = new Range(new Position($range->startLine, 0), new Position($range->endLine, 0));
            $this->world->assert(
                $this->world->rangeWithinDocument($path, $check),
                sprintf('folding range #%d (%d-%d) out of document bounds', $i, $range->startLine, $range->endLine),
            );
        }
    }

    /**
     * @Then every inlay hint position is within the bounds of :path
     */
    public function everyInlayHintPositionIsWithinTheBoundsOf(string $path): void
    {
        $hints = $this->world->last();
        $this->world->assert(is_array($hints) && $hints !== [], 'expected a non-empty inlay-hint list');
        foreach ($hints as $i => $hint) {
            if (!$hint instanceof InlayHint) {
                continue;
            }
            $point = new Range($hint->position, $hint->position);
            $this->world->assert(
                $this->world->rangeWithinDocument($path, $point),
                sprintf('inlay hint #%d position out of document bounds at %d:%d', $i, $hint->position->line, $hint->position->character),
            );
        }
    }

    private function assertHoverContains(string $needle): void
    {
        $hover = $this->world->last();
        $this->world->assert($hover instanceof Hover, 'expected a Hover response, got ' . get_debug_type($hover));
        $contents = $hover->contents;
        $text = $contents instanceof MarkupContent ? $contents->value : (is_string($contents) ? $contents : '');
        $this->world->assert(
            str_contains($text, $needle),
            sprintf('expected hover contents to contain "%s", got: %s', $needle, $text === '' ? '<empty>' : $text),
        );
    }
}
