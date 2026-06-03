<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Behat\Context\Context;
use Phpactor\LanguageServerProtocol\DocumentSymbol;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\SymbolKind;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentPositionParams;
use Phpactor\LanguageServerProtocol\WorkspaceSymbolParams;

/**
 * Steps for the Navigate theme: definition, type-definition, references,
 * implementation, document/workspace symbols, document highlight, and the call
 * & type hierarchies.
 */
final class NavigateContext implements Context
{
    /** @var array<string, mixed> the hierarchy item resolved by a prepare step */
    private array $hierarchyItem = [];

    public function __construct(private readonly World $world)
    {
    }

    // ---- call hierarchy ----------------------------------------------------

    /**
     * @When I prepare call hierarchy on :needle at line :line of :path
     */
    public function iPrepareCallHierarchyOnAtLineOf(string $needle, int $line, string $path): void
    {
        $params = new TextDocumentPositionParams(new TextDocumentIdentifier($path), $this->world->positionOfNeedle($path, $line, $needle));
        $items = $this->world->request('textDocument/prepareCallHierarchy', $params);
        $this->hierarchyItem = $this->itemDict(is_array($items) ? ($items[0] ?? null) : null, $path);
    }

    /**
     * @When I request incoming calls
     */
    public function iRequestIncomingCalls(): void
    {
        $this->world->request('callHierarchy/incomingCalls', ['item' => $this->hierarchyItem]);
    }

    /**
     * @When I request outgoing calls
     */
    public function iRequestOutgoingCalls(): void
    {
        $this->world->request('callHierarchy/outgoingCalls', ['item' => $this->hierarchyItem]);
    }

    /**
     * @Then the prepared item is named :name
     */
    public function thePreparedItemIsNamed(string $name): void
    {
        $names = $this->hierarchyNames($this->world->last(), 'name');
        $this->world->assert(
            in_array($name, $names, true),
            sprintf('expected a prepared item named "%s"; got: [%s]', $name, implode(', ', $names)),
        );
    }

    /**
     * @Then an incoming call comes from :name
     */
    public function anIncomingCallComesFrom(string $name): void
    {
        $names = $this->hierarchyNames($this->world->last(), 'from');
        $this->world->assert(
            in_array($name, $names, true),
            sprintf('expected an incoming call from "%s"; got: [%s]', $name, implode(', ', $names)),
        );
    }

    /**
     * @Then an outgoing call goes to :name
     */
    public function anOutgoingCallGoesTo(string $name): void
    {
        $names = $this->hierarchyNames($this->world->last(), 'to');
        $this->world->assert(
            in_array($name, $names, true),
            sprintf('expected an outgoing call to "%s"; got: [%s]', $name, implode(', ', $names)),
        );
    }

    /**
     * Pull a name list out of a hierarchy response. $field is 'name' (prepared
     * items), 'from' (incoming) or 'to' (outgoing).
     *
     * @return list<string>
     */
    private function hierarchyNames(mixed $response, string $field): array
    {
        $this->world->assert(is_array($response), 'expected a hierarchy list response');
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

    // ---- type hierarchy ----------------------------------------------------

    /**
     * @When I prepare type hierarchy on :needle at line :line of :path
     */
    public function iPrepareTypeHierarchyOnAtLineOf(string $needle, int $line, string $path): void
    {
        $params = new TextDocumentPositionParams(new TextDocumentIdentifier($path), $this->world->positionOfNeedle($path, $line, $needle));
        $items = $this->world->request('textDocument/prepareTypeHierarchy', $params);
        $this->hierarchyItem = $this->itemDict(is_array($items) ? ($items[0] ?? null) : null, $path);
    }

    /**
     * @When I request supertypes
     */
    public function iRequestSupertypes(): void
    {
        $this->world->request('typeHierarchy/supertypes', ['item' => $this->hierarchyItem]);
    }

    /**
     * @When I request subtypes
     */
    public function iRequestSubtypes(): void
    {
        $this->world->request('typeHierarchy/subtypes', ['item' => $this->hierarchyItem]);
    }

    /**
     * @Then a supertype is named :name
     */
    public function aSupertypeIsNamed(string $name): void
    {
        $this->assertRelatedTypeNamed($name, 'supertype');
    }

    /**
     * @Then a subtype is named :name
     */
    public function aSubtypeIsNamed(string $name): void
    {
        $this->assertRelatedTypeNamed($name, 'subtype');
    }

    private function assertRelatedTypeNamed(string $name, string $label): void
    {
        $names = $this->hierarchyNames($this->world->last(), 'name');
        $this->world->assert(
            in_array($name, $names, true),
            sprintf('expected a %s named "%s"; got: [%s]', $label, $name, implode(', ', $names)),
        );
    }

    /**
     * @Then a supertype :name has fqn :fqn
     * @Then a subtype :name has fqn :fqn
     */
    public function aRelatedTypeHasFqn(string $name, string $fqn): void
    {
        foreach ((array) $this->world->last() as $entry) {
            $entryName = is_array($entry) ? ($entry['name'] ?? null) : ($entry->name ?? null);
            if ($entryName !== $name) {
                continue;
            }
            $entryFqn = is_array($entry) ? ($entry['data']['fqn'] ?? null) : ($entry->data['fqn'] ?? null);
            $this->world->assert(
                $entryFqn === $fqn,
                sprintf('expected %s to have fqn "%s", got "%s"', $name, $fqn, (string) $entryFqn),
            );
            return;
        }
        $this->world->fail(sprintf('no related type named "%s"', $name));
    }

    // ---- covered-text assertions (references / highlights / outline) --------

    /**
     * @Then a reference in :path covers :text
     */
    public function aReferenceInCovers(string $path, string $text): void
    {
        $seen = [];
        foreach ((array) $this->world->last() as $loc) {
            if (!$loc instanceof Location || !$this->matchesPath($loc->uri, $path)) {
                continue;
            }
            $covered = $this->world->textForRange($loc->uri, $loc->range);
            $seen[] = $covered;
            if ($covered === $text) {
                return;
            }
        }
        $this->world->fail(sprintf('expected a reference in "%s" covering "%s"; got: [%s]', $path, $text, implode(', ', $seen)));
    }

    /**
     * @Then each highlight covers :text in :path
     */
    public function eachHighlightCoversIn(string $text, string $path): void
    {
        $highlights = $this->world->last();
        $this->world->assert(is_array($highlights) && $highlights !== [], 'expected a non-empty highlight list');
        foreach ($highlights as $highlight) {
            $covered = $this->world->textForRange($path, $highlight->range);
            $this->world->assert(
                $covered === $text,
                sprintf('expected each highlight to cover "%s", got "%s"', $text, $covered),
            );
        }
    }

    /**
     * @Then a :kind highlight covers :text in :path
     */
    public function aKindHighlightCoversIn(string $kind, string $text, string $path): void
    {
        $kinds = ['text' => 1, 'read' => 2, 'write' => 3];
        $this->world->assert(isset($kinds[$kind]), sprintf('unknown highlight kind: %s', $kind));
        $wantKind = $kinds[$kind];

        $highlights = $this->world->last();
        $this->world->assert(is_array($highlights) && $highlights !== [], 'expected a non-empty highlight list');
        $seen = [];
        foreach ($highlights as $highlight) {
            $covered = $this->world->textForRange($path, $highlight->range);
            if ($covered !== $text) {
                continue;
            }
            $seen[] = (int) $highlight->kind;
            if ((int) $highlight->kind === $wantKind) {
                return;
            }
        }
        $this->world->fail(sprintf(
            'expected a "%s" (%d) highlight covering "%s"; kinds seen for that text: [%s]',
            $kind,
            $wantKind,
            $text,
            implode(', ', $seen) ?: '<none>',
        ));
    }

    private function matchesPath(string $uri, string $path): bool
    {
        return $uri === $path
            || $this->world->stripFileScheme($uri) === $path
            || str_ends_with($uri, '/' . $path)
            || str_ends_with($this->world->stripFileScheme($uri), '/' . $path);
    }

    // ---- document-symbol structure -----------------------------------------

    /**
     * @Then the outline contains a class :name with :count members
     */
    public function theOutlineContainsAClassWithMembers(string $name, int $count): void
    {
        $class = $this->topLevelSymbol($name, SymbolKind::CLASS_);
        $this->world->assert($class !== null, sprintf('expected a top-level class named "%s"', $name));
        $children = is_array($class->children) ? $class->children : [];
        $this->world->assert(
            count($children) === $count,
            sprintf('expected class "%s" to have %d members, got %d', $name, $count, count($children)),
        );
    }

    /**
     * @Then the class :name has a :kind member named :member
     */
    public function theClassHasAMemberNamed(string $name, string $kind, string $member): void
    {
        $class = $this->topLevelSymbol($name, SymbolKind::CLASS_);
        $this->world->assert($class !== null, sprintf('expected a top-level class named "%s"', $name));
        $wantKind = $this->symbolKind($kind);
        foreach (is_array($class->children) ? $class->children : [] as $child) {
            if ($child instanceof DocumentSymbol && $child->name === $member && $child->kind === $wantKind) {
                return;
            }
        }
        $this->world->fail(sprintf('expected class "%s" to have a %s member named "%s"', $name, $kind, $member));
    }

    /**
     * @Then the :name selection range in :path covers :text
     */
    public function theSelectionRangeInCovers(string $name, string $path, string $text): void
    {
        $symbol = $this->topLevelSymbol($name, SymbolKind::CLASS_);
        $this->world->assert($symbol !== null, sprintf('expected a top-level class named "%s"', $name));
        $covered = $this->world->textForRange($path, $symbol->selectionRange);
        $this->world->assert(
            $covered === $text,
            sprintf('expected "%s" selection range to cover "%s", got "%s"', $name, $text, $covered),
        );
    }

    private function topLevelSymbol(string $name, int $kind): ?DocumentSymbol
    {
        foreach ((array) $this->world->last() as $symbol) {
            if ($symbol instanceof DocumentSymbol && $symbol->name === $name && $symbol->kind === $kind) {
                return $symbol;
            }
        }
        return null;
    }

    // ---- workspace-symbol exactness ----------------------------------------

    /**
     * @Then there is exactly :count workspace symbol
     * @Then there are exactly :count workspace symbols
     */
    public function thereAreExactlyWorkspaceSymbols(int $count): void
    {
        $names = $this->symbolNames();
        $this->world->assert(
            count($names) === $count,
            sprintf('expected exactly %d workspace symbols, got %d: [%s]', $count, count($names), implode(', ', $names)),
        );
    }

    /**
     * @Then the workspace symbol :name has kind :kind
     */
    public function theWorkspaceSymbolHasKind(string $name, string $kind): void
    {
        $wantKind = $this->symbolKind($kind);
        foreach ((array) $this->world->last() as $symbol) {
            if (is_object($symbol) && ($symbol->name ?? null) === $name) {
                $this->world->assert(
                    ($symbol->kind ?? null) === $wantKind,
                    sprintf('expected workspace symbol "%s" to have kind %s', $name, $kind),
                );
                return;
            }
        }
        $this->world->fail(sprintf('no workspace symbol named "%s"', $name));
    }

    // ---- definition / references / highlight / symbols ---------------------

    /**
     * @Then the response points to :path
     */
    public function theResponsePointsTo(string $path): void
    {
        $location = $this->world->expectLocation();
        $uri = $location->uri;
        $bare = $this->world->stripFileScheme($uri);
        // Open-doc handlers return the bare workspace uri; worse-reflection-backed
        // handlers (typeDefinition) emit file:// URIs -- accept either.
        $ok = $uri === $path
            || $bare === $path
            || str_ends_with($uri, '/' . $path)
            || str_ends_with($bare, '/' . $path);
        $this->world->assert($ok, sprintf('expected response to point to "%s", got "%s"', $path, $uri));
    }

    /**
     * @Then the response contains :count locations
     */
    public function theResponseContainsLocations(int $count): void
    {
        $uris = $this->world->locationUris($this->world->last());
        $this->world->assert(
            count($uris) === $count,
            sprintf('expected %d locations, got %d: [%s]', $count, count($uris), implode(', ', $uris)),
        );
    }

    /**
     * @When I search workspace symbols for :query
     */
    public function iSearchWorkspaceSymbolsFor(string $query): void
    {
        $this->world->request('workspace/symbol', new WorkspaceSymbolParams($query));
    }

    /**
     * @Then the workspace symbols include :name
     */
    public function theWorkspaceSymbolsInclude(string $name): void
    {
        $names = $this->symbolNames();
        $this->world->assert(
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
        $this->world->assert(
            !in_array($name, $names, true),
            sprintf('expected workspace symbols to exclude "%s"; got: [%s]', $name, implode(', ', $names)),
        );
    }

    /** @return list<string> */
    private function symbolNames(): array
    {
        $response = $this->world->last();
        $this->world->assert(is_array($response), 'expected a workspace-symbol list response');
        $names = [];
        foreach ($response as $symbol) {
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
        $response = $this->world->last();
        $this->world->assert(is_array($response), 'expected a document-symbol list response');
        $found = $this->findSymbol($response, $name, $this->symbolKind($kind));
        $this->world->assert(
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
        $response = $this->world->last();
        $this->world->assert(is_array($response), 'expected a highlight list response');
        $this->world->assert(
            count($response) === $count,
            sprintf('expected %d highlights, got %d', $count, count($response)),
        );
    }

    /**
     * @Then the response includes a location in :path
     */
    public function theResponseIncludesALocationIn(string $path): void
    {
        $uris = $this->world->locationUris($this->world->last());
        foreach ($uris as $uri) {
            if ($uri === $path || $this->world->stripFileScheme($uri) === $path || str_ends_with($uri, '/' . $path)) {
                return;
            }
        }
        $this->world->fail(sprintf('expected a location in "%s"; got: [%s]', $path, implode(', ', $uris)));
    }

    /**
     * @Then the target range covers the :name class name
     */
    public function theTargetRangeCoversTheClassName(string $name): void
    {
        $covered = $this->world->textInRange($this->world->expectLocation());
        $this->world->assert(
            $covered === $name,
            sprintf('expected target range to cover "%s", got "%s"', $name, $covered),
        );
    }

    /**
     * @Then the target range covers the :name method declaration
     */
    public function theTargetRangeCoversTheMethodDeclaration(string $name): void
    {
        $covered = $this->world->textInRange($this->world->expectLocation());
        $this->world->assert(
            $covered === $name,
            sprintf('expected target range to cover "%s", got "%s"', $name, $covered),
        );
    }
}
