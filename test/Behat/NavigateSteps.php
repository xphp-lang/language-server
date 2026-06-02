<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Phpactor\LanguageServerProtocol\DocumentSymbol;
use Phpactor\LanguageServerProtocol\SymbolKind;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentPositionParams;
use Phpactor\LanguageServerProtocol\WorkspaceSymbolParams;

use function Amp\Promise\wait;

/**
 * Steps for the Navigate theme: definition, type-definition, references,
 * implementation, document/workspace symbols, document highlight, and the call
 * & type hierarchies.
 */
trait NavigateSteps
{
    /** @var array<string, mixed> the hierarchy item resolved by a prepare step */
    private array $hierarchyItem = [];

    // ---- call hierarchy ----------------------------------------------------

    /**
     * @When I prepare call hierarchy on :needle at line :line of :path
     */
    public function iPrepareCallHierarchyOnAtLineOf(string $needle, int $line, string $path): void
    {
        $params = new TextDocumentPositionParams(new TextDocumentIdentifier($path), $this->positionOfNeedle($path, $line, $needle));
        $items = wait($this->handler('callHierarchy')->prepare($params));
        $this->lastResponse = $items;
        $this->hierarchyItem = $this->itemDict($items[0] ?? null, $path);
    }

    /**
     * @When I request incoming calls
     */
    public function iRequestIncomingCalls(): void
    {
        $this->lastResponse = wait($this->handler('callHierarchy')->incomingCalls($this->hierarchyItem));
    }

    /**
     * @When I request outgoing calls
     */
    public function iRequestOutgoingCalls(): void
    {
        $this->lastResponse = wait($this->handler('callHierarchy')->outgoingCalls($this->hierarchyItem));
    }

    /**
     * @Then the prepared item is named :name
     */
    public function thePreparedItemIsNamed(string $name): void
    {
        $names = $this->hierarchyNames($this->lastResponse, 'name');
        $this->assert(
            in_array($name, $names, true),
            sprintf('expected a prepared item named "%s"; got: [%s]', $name, implode(', ', $names)),
        );
    }

    /**
     * @Then an incoming call comes from :name
     */
    public function anIncomingCallComesFrom(string $name): void
    {
        $names = $this->hierarchyNames($this->lastResponse, 'from');
        $this->assert(
            $this->anyContains($names, $name),
            sprintf('expected an incoming call from "%s"; got: [%s]', $name, implode(', ', $names)),
        );
    }

    /**
     * @Then an outgoing call goes to :name
     */
    public function anOutgoingCallGoesTo(string $name): void
    {
        $names = $this->hierarchyNames($this->lastResponse, 'to');
        $this->assert(
            $this->anyContains($names, $name),
            sprintf('expected an outgoing call to "%s"; got: [%s]', $name, implode(', ', $names)),
        );
    }

    /**
     * @param array<string> $haystacks
     */
    private function anyContains(array $haystacks, string $needle): bool
    {
        foreach ($haystacks as $h) {
            if (str_contains($h, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pull a name list out of a hierarchy response. $field is 'name' (prepared
     * items), 'from' (incoming) or 'to' (outgoing).
     *
     * @param mixed $response
     * @return list<string>
     */
    private function hierarchyNames(mixed $response, string $field): array
    {
        $this->assert(is_array($response), 'expected a hierarchy list response');
        $names = [];
        foreach ($response as $entry) {
            $target = match ($field) {
                'name' => $entry,
                'from' => $entry->from ?? null,
                'to' => $entry->to ?? null,
                default => null,
            };
            if (is_object($target) && isset($target->name)) {
                $names[] = $target->name;
            } elseif (is_array($target) && isset($target['name'])) {
                $names[] = $target['name'];
            }
        }
        return $names;
    }

    /**
     * Convert a prepared item (object or array) into the array dict the
     * incoming/outgoing handlers expect (the client round-trips it as JSON).
     *
     * @return array<string, mixed>
     */
    private function itemDict(mixed $item, string $fallbackUri): array
    {
        if (is_array($item)) {
            return $item + ['uri' => $fallbackUri];
        }
        if (is_object($item)) {
            return [
                'uri' => $item->uri ?? $fallbackUri,
                'data' => $item->data ?? [],
            ];
        }
        return ['uri' => $fallbackUri, 'data' => []];
    }

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
