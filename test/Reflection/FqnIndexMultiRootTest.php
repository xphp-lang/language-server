<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Reflection;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Pins the WI-13 multi-root behaviour of {@see FqnIndex}: the filesystem walk
 * spans the primary `rootPath` plus the manifest-derived extra source roots
 * (which may live outside `rootPath`), indexes a file reached through more than
 * one root exactly once, and prunes the manifest's build-output / cache dirs so
 * generated PHP is never indexed as source.
 */
final class FqnIndexMultiRootTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/xphp-fqn-multiroot-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->base);
    }

    public function testIndexesDeclarationsFromAnExtraRootOutsideRootPath(): void
    {
        $rootA = $this->makeDir('pkgA');
        $rootB = $this->makeDir('pkgB');
        $this->write($rootA . '/A.xphp', "<?php\nnamespace App;\nclass Alpha {}\n");
        $this->write($rootB . '/B.xphp', "<?php\nnamespace App;\nclass Beta {}\n");

        // rootB is NOT under rootA -- it is only reachable as an extra root.
        $index = $this->index($rootA, [$rootB]);

        self::assertSame(realpath($rootA . '/A.xphp'), $index->pathFor('App\\Alpha'));
        self::assertSame(realpath($rootB . '/B.xphp'), $index->pathFor('App\\Beta'));
    }

    public function testFileReachableThroughOverlappingRootsIsIndexedOnce(): void
    {
        $root = $this->makeDir('proj');
        $nested = $this->makeDir('proj/src');
        $this->write($nested . '/C.xphp', "<?php\nnamespace App;\nclass Gamma {}\n");

        // The nested root overlaps the primary root -- C.xphp is reachable via
        // both. The walk must visit it once (the per-file seen-set), otherwise
        // its declaration records would be appended twice.
        $index = $this->index($root, [$nested]);

        $paths = $index->indexedFilesystemPaths();
        $target = realpath($nested . '/C.xphp');
        $hits = array_filter($paths, static fn (string $p): bool => realpath($p) === $target);
        self::assertCount(1, $hits, 'a doubly-reachable file must be walked once');
    }

    public function testExcludedOutputDirIsNotIndexed(): void
    {
        $root = $this->makeDir('app');
        $this->write($root . '/src/Widget.xphp', "<?php\nnamespace App;\nclass Widget {}\n");
        // A generated specialization the compiler would emit under the build dir.
        $this->write($root . '/build/Widget_abcd.php', "<?php\nnamespace XPHP\\Generated;\nclass Widget_abcd {}\n");

        $index = $this->index($root, [], [$root . '/build']);

        self::assertSame(realpath($root . '/src/Widget.xphp'), $index->pathFor('App\\Widget'));
        self::assertNull(
            $index->pathFor('XPHP\\Generated\\Widget_abcd'),
            'generated PHP under the excluded build dir must not be indexed',
        );
    }

    public function testEmptyRootPathWithNoExtraRootsIndexesNothing(): void
    {
        // The "no filesystem walk" sentinel still holds under the multi-root code.
        $index = $this->index('', []);

        self::assertNull($index->pathFor('App\\Anything'));
        self::assertSame([], $index->indexedFilesystemPaths());
    }

    /**
     * @param list<string> $extraRoots
     * @param list<string> $excludedDirs
     */
    private function index(string $rootPath, array $extraRoots, array $excludedDirs = []): FqnIndex
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));

        return new FqnIndex(new PhpactorWorkspace(), $cache, $parser, $rootPath, $extraRoots, $excludedDirs);
    }

    private function makeDir(string $relative): string
    {
        $path = $this->base . '/' . $relative;
        if (!is_dir($path)) {
            mkdir($path, 0o755, true);
        }

        return $path;
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
