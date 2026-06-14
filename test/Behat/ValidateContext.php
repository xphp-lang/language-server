<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;

/**
 * Steps for the Validate theme: diagnostics (parse errors, generic bound
 * violations, duplicate templates, undefined barewords, argument-type
 * mismatches). Diagnostics are pulled through the real XphpPullDiagnosticsHandler
 * over the open workspace -- cross-file checks see every open document.
 *
 * The cross-file broadcast steps additionally drive the PUSH path: the real
 * diagnostics engine re-publishes a dependent's diagnostics when an unrelated
 * file it depends on is edited.
 */
final class ValidateContext implements Context
{
    private string $analyzedPath = '';

    public function __construct(private readonly World $world)
    {
    }

    /**
     * @Given the diagnostics service is running
     */
    public function theDiagnosticsServiceIsRunning(): void
    {
        $this->world->startDiagnosticsService();
    }

    /**
     * @When I change the file at :path to contain the following lines:
     */
    public function iChangeTheFileToContain(string $path, PyStringNode $lines): void
    {
        $this->world->changeFile($path, $lines->getRaw());
    }

    /**
     * @Then a :code diagnostic is published for :path without re-requesting it
     */
    public function aDiagnosticIsPublishedFor(string $code, string $path): void
    {
        // Pump the cooperative loop until the broadcast publishes a diagnostic
        // with this code for the (untouched) dependent, or give up.
        for ($try = 0; $try < 100; $try++) {
            foreach ($this->world->publishedDiagnostics($path) as $params) {
                foreach ($params['diagnostics'] as $diagnostic) {
                    if ((string) ($diagnostic->code ?? '') === $code) {
                        return;
                    }
                }
            }
            $this->world->pumpLoop();
        }
        $this->world->fail(sprintf(
            'expected a "%s" diagnostic to be broadcast for "%s"; none was published',
            $code,
            $path,
        ));
    }

    /**
     * @When I analyze :path for diagnostics
     */
    public function iAnalyzeForDiagnostics(string $path): void
    {
        // Pull-mode diagnostics through the real XphpPullDiagnosticsHandler, which
        // returns a `{kind: 'full', items: [...]}` DocumentDiagnosticReport.
        $this->analyzedPath = $path;
        $this->world->request('textDocument/diagnostic', ['textDocument' => ['uri' => $path]]);
    }

    /**
     * @Then the :code diagnostic underlines :text
     */
    public function theDiagnosticUnderlines(string $code, string $text): void
    {
        $seen = [];
        foreach ($this->diagnosticItems() as $diagnostic) {
            if (($diagnostic->code ?? null) !== $code) {
                continue;
            }
            $covered = $this->world->textForRange($this->analyzedPath, $diagnostic->range);
            $seen[] = $covered;
            if ($covered === $text) {
                return;
            }
        }
        $this->world->fail(sprintf(
            'expected the "%s" diagnostic to underline "%s"; underlined: [%s]',
            $code,
            $text,
            implode(' | ', $seen) ?: '<none>',
        ));
    }

    /**
     * @Then a :code diagnostic is reported
     */
    public function aDiagnosticIsReported(string $code): void
    {
        $codes = $this->diagnosticCodes();
        $this->world->assert(
            in_array($code, $codes, true),
            sprintf('expected a "%s" diagnostic; got: [%s]', $code, implode(', ', $codes)),
        );
    }

    /**
     * @Then a :code diagnostic is reported saying :text
     */
    public function aDiagnosticIsReportedSaying(string $code, string $text): void
    {
        $messages = [];
        foreach ($this->diagnosticItems() as $diagnostic) {
            if (($diagnostic->code ?? null) !== $code) {
                continue;
            }
            $messages[] = $diagnostic->message;
            if (str_contains($diagnostic->message, $text)) {
                return;
            }
        }
        $this->world->fail(sprintf(
            'expected a "%s" diagnostic saying "%s"; got messages: [%s]',
            $code,
            $text,
            implode(' | ', $messages) ?: '<none>',
        ));
    }

    /**
     * @Then every reported diagnostic range is within document bounds
     */
    public function everyDiagnosticRangeIsWithinDocumentBounds(): void
    {
        foreach ($this->diagnosticItems() as $diagnostic) {
            $this->world->assert(
                $this->world->rangeWithinDocument($this->analyzedPath, $diagnostic->range),
                sprintf(
                    'diagnostic range out of document bounds (%d:%d-%d:%d): %s',
                    $diagnostic->range->start->line,
                    $diagnostic->range->start->character,
                    $diagnostic->range->end->line,
                    $diagnostic->range->end->character,
                    $diagnostic->message ?? '',
                ),
            );
        }
    }

    /**
     * @Then no diagnostics are reported
     */
    public function noDiagnosticsAreReported(): void
    {
        $codes = $this->diagnosticCodes();
        $this->world->assert($codes === [], 'expected no diagnostics; got: [' . implode(', ', $codes) . ']');
    }

    /** @return list<string> */
    private function diagnosticCodes(): array
    {
        $codes = [];
        foreach ($this->diagnosticItems() as $diagnostic) {
            if (is_object($diagnostic) && isset($diagnostic->code)) {
                $codes[] = (string) $diagnostic->code;
            }
        }
        return $codes;
    }

    /**
     * Extract the diagnostic list from the pull-mode report
     * (`{kind: 'full', items: [...]}`).
     *
     * @return list<object>
     */
    private function diagnosticItems(): array
    {
        $report = $this->world->last();
        $this->world->assert(is_array($report), 'expected a diagnostic report, got ' . get_debug_type($report));
        $items = $report['items'] ?? $report;
        $this->world->assert(is_array($items), 'expected the report to carry an items list');
        return array_values($items);
    }
}
