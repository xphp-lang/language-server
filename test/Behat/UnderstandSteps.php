<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\InlayHint;
use Phpactor\LanguageServerProtocol\MarkupContent;

/**
 * Steps for the Understand theme: hover, signature help, inlay hints, folding
 * ranges, and semantic tokens.
 */
trait UnderstandSteps
{
    /**
     * @Then the hover contents describe the class :fqn
     */
    public function theHoverContentsDescribeTheClass(string $fqn): void
    {
        $this->assertHoverContains($fqn);
    }

    /**
     * @Then the hover contents show the substituted signature :sig
     */
    public function theHoverContentsShowTheSubstitutedSignature(string $sig): void
    {
        $this->assertHoverContains($sig);
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
