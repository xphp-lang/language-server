<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Reflection;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\OpenedProjectIndexer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * End-to-end regression for the multi-root Track A fix, against durable
 * committed fixtures: two sibling projects (`app` + `lib`), each with its own
 * `xphp.json`. Mirrors the real failure — workspace rooted at one project, a
 * file opened from a sibling — and asserts the whole navigation query surface
 * (go-to-definition, definition-by-path, and completion/import short-name
 * lookup) resolves the sibling's symbols only AFTER the sibling file is opened.
 */
final class MultiRootNavigationTest extends TestCase
{
    private function fixtureRoot(string $rel): string
    {
        return dirname(__DIR__) . '/fixture/multiroot/' . $rel;
    }

    public function testOpeningASiblingProjectMakesItsSymbolsNavigable(): void
    {
        // Workspace rooted at `app` only — `lib`'s Widget is not yet indexed.
        $index = $this->indexRootedAt($this->fixtureRoot('app/src'));

        self::assertNotNull($index->pathFor('App\\Consumer'), 'the rooted project resolves');
        self::assertNull($index->pathFor('Lib\\Widget'), 'the sibling is unresolved before its file is opened');
        self::assertNull($index->locationForFqn('Lib\\Widget'), 'GTD finds nothing before open');
        self::assertNotContains('Lib\\Widget', $index->fqnsByShortName('Widget'), 'completion offers nothing before open');

        // Open a file from the `lib` sibling project.
        $opened = $this->fixtureRoot('lib/src/Widget.xphp');
        self::assertTrue((new OpenedProjectIndexer($index))->register($opened), 'the sibling project is registered');

        // Now the full navigation surface resolves Lib\Widget.
        self::assertSame(realpath($opened), $index->pathFor('Lib\\Widget'), 'definition resolves to the sibling file');
        self::assertNotNull($index->locationForFqn('Lib\\Widget'), 'go-to-definition resolves the sibling symbol');
        self::assertContains('Lib\\Widget', $index->fqnsByShortName('Widget'), 'completion/import offers the sibling symbol');
    }

    private function indexRootedAt(string $rootPath): FqnIndex
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));

        return new FqnIndex(new PhpactorWorkspace(), $cache, $parser, $rootPath);
    }
}
