<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Phpactor\LanguageServerProtocol\DocumentSymbol;
use Phpactor\LanguageServerProtocol\SymbolKind;
use Phpactor\LanguageServerProtocol\WorkspaceSymbolParams;

use function Amp\Promise\wait;

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
     * @When I search workspace symbols for :query
     */
    public function iSearchWorkspaceSymbolsFor(string $query): void
    {
        $this->lastResponse = wait($this->handler('workspaceSymbol')->symbol(new WorkspaceSymbolParams($query)));
    }

    /**
     * @Then the workspace symbols include :name
     */
    public function theWorkspaceSymbolsInclude(string $name): void
    {
        $names = $this->symbolNames();
        $this->assert(
            in_array($name, $names, true),
            sprintf('expected workspace symbols to include "%s"; got: [%s]', $name, implode(', ', $names)),
        );
    }

    /**
     * @Then the workspace symbols exclude :name
     */
    public function theWorkspaceSymbolsExclude(string $name): void
    {
        $names = $this->symbolNames();
        $this->assert(
            !in_array($name, $names, true),
            sprintf('expected workspace symbols to exclude "%s"; got: [%s]', $name, implode(', ', $names)),
        );
    }

    /** @return list<string> */
    private function symbolNames(): array
    {
        $this->assert(is_array($this->lastResponse), 'expected a workspace-symbol list response');
        $names = [];
        foreach ($this->lastResponse as $symbol) {
            if (is_object($symbol) && isset($symbol->name)) {
                $names[] = $symbol->name;
            }
        }
        return $names;
    }

    /**
     * @Then the document outline contains a :kind named :name
     */
    public function theDocumentOutlineContainsANamed(string $kind, string $name): void
    {
        $this->assert(is_array($this->lastResponse), 'expected a document-symbol list response');
        $wantKind = $this->symbolKind($kind);
        $found = $this->findSymbol($this->lastResponse, $name, $wantKind);
        $this->assert(
            $found,
            sprintf('expected outline to contain a %s named "%s"', $kind, $name),
        );
    }

    /**
     * @param list<DocumentSymbol> $symbols
     */
    private function findSymbol(array $symbols, string $name, int $kind): bool
    {
        foreach ($symbols as $symbol) {
            if (!$symbol instanceof DocumentSymbol) {
                continue;
            }
            if ($symbol->name === $name && $symbol->kind === $kind) {
                return true;
            }
            if (is_array($symbol->children) && $this->findSymbol($symbol->children, $name, $kind)) {
                return true;
            }
        }
        return false;
    }

    private function symbolKind(string $kind): int
    {
        return match ($kind) {
            'class' => SymbolKind::CLASS_,
            'interface' => SymbolKind::INTERFACE,
            'enum' => SymbolKind::ENUM,
            'method' => SymbolKind::METHOD,
            'constructor' => SymbolKind::CONSTRUCTOR,
            'property' => SymbolKind::PROPERTY,
            'constant' => SymbolKind::CONSTANT,
            'function' => SymbolKind::FUNCTION,
            default => throw new \RuntimeException("unknown symbol kind: {$kind}"),
        };
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
