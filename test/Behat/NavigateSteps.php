<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

/**
 * Steps for the Navigate theme: definition, type-definition, references,
 * implementation, document/workspace symbols, document highlight, and the call
 * & type hierarchies.
 */
trait NavigateSteps
{
    /**
     * @Then the response points to :path
     */
    public function theResponsePointsTo(string $path): void
    {
        $location = $this->expectLocation();
        $this->assert(
            $location->uri === $path || $this->stripFileScheme($location->uri) === $path,
            sprintf('expected response to point to "%s", got "%s"', $path, $location->uri),
        );
    }

    /**
     * @Then the target range covers the :name class name
     */
    public function theTargetRangeCoversTheClassName(string $name): void
    {
        $covered = $this->textInRange($this->expectLocation());
        $this->assert(
            $covered === $name,
            sprintf('expected target range to cover "%s", got "%s"', $name, $covered),
        );
    }

    /**
     * @Then the target range covers the :name method declaration
     */
    public function theTargetRangeCoversTheMethodDeclaration(string $name): void
    {
        $covered = $this->textInRange($this->expectLocation());
        $this->assert(
            $covered === $name,
            sprintf('expected target range to cover "%s", got "%s"', $name, $covered),
        );
    }
}
