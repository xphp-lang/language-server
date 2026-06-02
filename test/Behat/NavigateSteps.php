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
        $uri = $location->uri;
        $bare = $this->stripFileScheme($uri);
        // Open-doc handlers return the bare workspace uri; worse-reflection-backed
        // handlers (typeDefinition) emit file:// URIs -- accept either.
        $ok = $uri === $path
            || $bare === $path
            || str_ends_with($uri, '/' . $path)
            || str_ends_with($bare, '/' . $path);
        $this->assert($ok, sprintf('expected response to point to "%s", got "%s"', $path, $uri));
    }

    /**
     * @Then the response contains :count locations
     */
    public function theResponseContainsLocations(int $count): void
    {
        $uris = $this->locationUris($this->lastResponse);
        $this->assert(
            count($uris) === $count,
            sprintf('expected %d locations, got %d: [%s]', $count, count($uris), implode(', ', $uris)),
        );
    }

    /**
     * @Then the response contains :count highlights
     */
    public function theResponseContainsHighlights(int $count): void
    {
        $this->assert(is_array($this->lastResponse), 'expected a highlight list response');
        $this->assert(
            count($this->lastResponse) === $count,
            sprintf('expected %d highlights, got %d', $count, count($this->lastResponse)),
        );
    }

    /**
     * @Then the response includes a location in :path
     */
    public function theResponseIncludesALocationIn(string $path): void
    {
        $uris = $this->locationUris($this->lastResponse);
        foreach ($uris as $uri) {
            if ($uri === $path || $this->stripFileScheme($uri) === $path || str_ends_with($uri, '/' . $path)) {
                return;
            }
        }
        $this->fail(sprintf('expected a location in "%s"; got: [%s]', $path, implode(', ', $uris)));
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
