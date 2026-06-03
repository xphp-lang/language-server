<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Behat\Context\Context;

/**
 * Steps for the Validate theme: diagnostics (parse errors, generic bound
 * violations, duplicate templates, undefined barewords, constructor-arg
 * mismatches). Diagnostics are pulled through the real XphpPullDiagnosticsHandler
 * over the open workspace -- cross-file checks see every open document.
 */
final class ValidateContext implements Context
{
    public function __construct(private readonly World $world)
    {
    }

    /**
     * @When I analyze :path for diagnostics
     */
    public function iAnalyzeForDiagnostics(string $path): void
    {
        // Pull-mode diagnostics through the real XphpPullDiagnosticsHandler, which
        // returns a `{kind: 'full', items: [...]}` DocumentDiagnosticReport.
        $this->world->request('textDocument/diagnostic', ['textDocument' => ['uri' => $path]]);
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
