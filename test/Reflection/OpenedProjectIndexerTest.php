<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Reflection;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServer\Event\TextDocumentOpened;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\OpenedProjectIndexer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * On `didOpen`, opening a file from a project OUTSIDE the workspace root folds
 * that project's declared source roots into the FQN index, so its symbols
 * resolve. Uses temp project trees with real `xphp.json` manifests.
 */
final class OpenedProjectIndexerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/xphp-opened-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->base);
    }

    public function testOpeningASiblingProjectFileRegistersItsSourceRoots(): void
    {
        // Source root is the project dir itself ("."), so the manifest's output
        // and cache dirs sit INSIDE the walk and the exclusion is load-bearing
        // (dir names chosen to avoid FqnIndex::SKIP_DIRS like `build`).
        $proj = $this->base . '/collections';
        $this->write($proj . '/xphp.json', '{"sources": ["."], "target": "gen", "cache": "cch"}');
        $this->write($proj . '/ImmutableList.xphp', "<?php\nnamespace Coll;\nclass ImmutableList {}\n");
        $this->write($proj . '/gen/Gen.xphp', "<?php\nnamespace Coll\\Gen;\nclass Gen {}\n");
        $this->write($proj . '/cch/Cached.xphp', "<?php\nnamespace Coll\\Cch;\nclass Cached {}\n");

        $index = $this->emptyIndex();
        self::assertNull($index->pathFor('Coll\\ImmutableList'), 'not indexed before open');

        $added = (new OpenedProjectIndexer($index))->register($proj . '/ImmutableList.xphp');

        self::assertTrue($added, 'the sibling project was registered');
        self::assertSame(
            realpath($proj . '/ImmutableList.xphp'),
            $index->pathFor('Coll\\ImmutableList'),
            'a symbol declared in the opened project now resolves',
        );
        self::assertNull($index->pathFor('Coll\\Gen\\Gen'), 'the manifest output dir is excluded');
        self::assertNull($index->pathFor('Coll\\Cch\\Cached'), 'the manifest cache dir is excluded');
    }

    public function testOnOpenDelegatesToRegister(): void
    {
        $this->write($this->base . '/proj/xphp.json', '{"sources": ["src"]}');
        $this->write($this->base . '/proj/src/Thing.xphp', "<?php\nnamespace P;\nclass Thing {}\n");

        $index = $this->emptyIndex();
        $indexer = new OpenedProjectIndexer($index);
        $uri = 'file://' . $this->base . '/proj/src/Thing.xphp';
        $indexer->onOpen(new TextDocumentOpened(new TextDocumentItem($uri, 'xphp', 1, '<?php')));

        self::assertSame(realpath($this->base . '/proj/src/Thing.xphp'), $index->pathFor('P\\Thing'));
    }

    public function testNoManifestAboveTheFileIsANoOp(): void
    {
        $this->write($this->base . '/loose/Free.xphp', "<?php\nnamespace L;\nclass Free {}\n");
        $index = $this->emptyIndex();

        self::assertFalse((new OpenedProjectIndexer($index))->register($this->base . '/loose/Free.xphp'));
    }

    public function testNonFileUriIsANoOp(): void
    {
        self::assertFalse((new OpenedProjectIndexer($this->emptyIndex()))->register(null));
    }

    public function testSubscribesToOpenEventsOnly(): void
    {
        $indexer = new OpenedProjectIndexer($this->emptyIndex());
        $opened = new TextDocumentOpened(new TextDocumentItem('file:///x.xphp', 'xphp', 1, '<?php'));
        self::assertEquals([[$indexer, 'onOpen']], [...$indexer->getListenersForEvent($opened)]);
        self::assertSame([], [...$indexer->getListenersForEvent(new \stdClass())]);
    }

    private function emptyIndex(): FqnIndex
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));

        return new FqnIndex(new PhpactorWorkspace(), $cache, $parser, '');
    }

    private function write(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($path, $contents);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }
}
