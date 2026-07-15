<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Project;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Project\XphpManifest;

/**
 * Pins {@see XphpManifest} -- the defensive reader over an `xphp.json` project
 * manifest. Every malformed / absent case must degrade to `null` (the
 * single-root fallback signal) without throwing; a valid manifest resolves its
 * relative paths to absolute filesystem roots.
 */
final class XphpManifestTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-manifest-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    public function testParsesAValidManifestAndResolvesRelativePaths(): void
    {
        $path = $this->write('xphp.json', (string) json_encode([
            'sources' => ['src', '.'],
            'include' => ['vendor/*/*', 'packages/**'],
            'target' => 'build',
            'cache' => '.xphp-cache',
        ]));

        $manifest = XphpManifest::fromFile($path);

        self::assertNotNull($manifest);
        $base = realpath($this->root);
        self::assertSame($base, $manifest->baseDir);
        self::assertSame([$base . '/src', $base], $manifest->sourceRoots());
        self::assertSame(['vendor/*/*', 'packages/**'], $manifest->includes());
        self::assertSame($base . '/build', $manifest->outputDir());
        self::assertSame($base . '/.xphp-cache', $manifest->cacheDir());
    }

    public function testGlobstarIncludeIsSurfacedVerbatim(): void
    {
        $path = $this->write('xphp.json', (string) json_encode(['include' => ['packages/**']]));

        $manifest = XphpManifest::fromFile($path);

        self::assertNotNull($manifest);
        self::assertSame(['packages/**'], $manifest->includes());
    }

    public function testAbsentSourcesDefaultsToTheManifestDirectory(): void
    {
        // `{}` decodes to defaults: sources -> ["."], resolving to the base dir.
        $path = $this->write('xphp.json', '{}');

        $manifest = XphpManifest::fromFile($path);

        self::assertNotNull($manifest);
        self::assertSame([realpath($this->root)], $manifest->sourceRoots());
        self::assertSame([], $manifest->includes());
        self::assertNull($manifest->outputDir());
        self::assertNull($manifest->cacheDir());
    }

    public function testEmptyTopLevelArrayDecodesToDefaultsNotNull(): void
    {
        // `[]` and `{}` both decode to `[]`; upstream treats the empty case as an
        // empty object (defaults), so this is NOT the "non-object" error path.
        $path = $this->write('xphp.json', '[]');

        $manifest = XphpManifest::fromFile($path);

        self::assertNotNull($manifest);
        self::assertSame([realpath($this->root)], $manifest->sourceRoots());
    }

    public function testAbsoluteSourceRootIsPassedThroughUnchanged(): void
    {
        $abs = $this->root . '/external';
        $path = $this->write('xphp.json', (string) json_encode(['sources' => [$abs]]));

        $manifest = XphpManifest::fromFile($path);

        self::assertNotNull($manifest);
        // An already-absolute root is not re-based onto the manifest dir.
        self::assertSame([$abs], $manifest->sourceRoots());
    }

    public function testMissingFileYieldsNullFallbackSignal(): void
    {
        self::assertNull(XphpManifest::fromFile($this->root . '/nope.json'));
    }

    public function testMalformedJsonYieldsNullWithoutThrowing(): void
    {
        $path = $this->write('xphp.json', '{ this is not : json');

        self::assertNull(XphpManifest::fromFile($path));
    }

    public function testTopLevelNonObjectYieldsNull(): void
    {
        $path = $this->write('xphp.json', '[1, 2, 3]');

        self::assertNull(XphpManifest::fromFile($path));
    }

    public function testWrongTypedFieldYieldsNull(): void
    {
        // `sources` must be an array of strings; a string is a hard error upstream,
        // which the reader swallows into the fallback signal.
        $path = $this->write('xphp.json', (string) json_encode(['sources' => 'src']));

        self::assertNull(XphpManifest::fromFile($path));
    }

    public function testLocateWalksUpToFindTheManifest(): void
    {
        $this->write('xphp.json', '{}');
        mkdir($this->root . '/a/b/c', 0o755, true);

        $located = XphpManifest::locate($this->root . '/a/b/c');

        self::assertSame(realpath($this->root . '/xphp.json'), $located);
    }

    public function testLocateReturnsNullWhenNoManifestExists(): void
    {
        mkdir($this->root . '/lonely', 0o755, true);

        // No xphp.json anywhere under the temp root; the walk stops at the fs root.
        // (A stray xphp.json above sys_get_temp_dir is implausible in CI.)
        self::assertNull(XphpManifest::locate($this->root . '/lonely'));
    }

    public function testDiscoverAutoDetectsByWalkingUp(): void
    {
        $this->write('xphp.json', (string) json_encode(['sources' => ['lib']]));
        mkdir($this->root . '/deep/nested', 0o755, true);

        $manifest = XphpManifest::discover($this->root . '/deep/nested');

        self::assertNotNull($manifest);
        self::assertSame(realpath($this->root . '/xphp.json'), $manifest->path);
        self::assertSame([realpath($this->root) . '/lib'], $manifest->sourceRoots());
    }

    public function testDiscoverHonoursAnExplicitConfigFileOverride(): void
    {
        $override = $this->write('custom/elsewhere.json', (string) json_encode(['sources' => ['app']]));
        // A different (auto-detectable) manifest at the root must be ignored when
        // an explicit config path is given.
        $this->write('xphp.json', (string) json_encode(['sources' => ['should-not-win']]));

        $manifest = XphpManifest::discover($this->root, $override);

        self::assertNotNull($manifest);
        self::assertSame(realpath($override), $manifest->path);
        self::assertSame([realpath($this->root) . '/custom/app'], $manifest->sourceRoots());
    }

    public function testDiscoverAcceptsAConfigDirectoryContainingTheManifest(): void
    {
        $this->write('proj/xphp.json', (string) json_encode(['sources' => ['src']]));

        $manifest = XphpManifest::discover($this->root, $this->root . '/proj');

        self::assertNotNull($manifest);
        self::assertSame(realpath($this->root . '/proj/xphp.json'), $manifest->path);
    }

    public function testDiscoverConfigDirectoryWithTrailingSlashIsHandled(): void
    {
        $this->write('proj/xphp.json', (string) json_encode(['sources' => ['src']]));

        // A trailing separator on the config dir must not double up when joined
        // with the manifest filename.
        $manifest = XphpManifest::discover($this->root, $this->root . '/proj/');

        self::assertNotNull($manifest);
        self::assertSame(realpath($this->root . '/proj/xphp.json'), $manifest->path);
    }

    public function testSourceRootWithSurroundingWhitespaceIsTrimmed(): void
    {
        $path = $this->write('xphp.json', (string) json_encode(['sources' => ['  lib  ']]));

        $manifest = XphpManifest::fromFile($path);

        self::assertNotNull($manifest);
        // The padded entry resolves to `<base>/lib`, not `<base>/  lib  `.
        self::assertSame([realpath($this->root) . '/lib'], $manifest->sourceRoots());
    }

    public function testWhitespaceOnlySourceRootResolvesToBaseDir(): void
    {
        $path = $this->write('xphp.json', (string) json_encode(['sources' => ['   ']]));

        $manifest = XphpManifest::fromFile($path);

        self::assertNotNull($manifest);
        self::assertSame([realpath($this->root)], $manifest->sourceRoots());
    }

    public function testWindowsStyleAbsoluteSourceRootIsPassedThrough(): void
    {
        $abs = 'C:\\packages\\lib';
        $path = $this->write('xphp.json', (string) json_encode(['sources' => [$abs]]));

        $manifest = XphpManifest::fromFile($path);

        self::assertNotNull($manifest);
        // Recognised as absolute (drive-letter), so not re-based onto the manifest dir.
        self::assertSame([$abs], $manifest->sourceRoots());
    }

    public function testOutputDirWithNestedRelativePathResolves(): void
    {
        $path = $this->write('xphp.json', (string) json_encode(['target' => 'build/out', 'cache' => 'tmp/gen']));

        $manifest = XphpManifest::fromFile($path);

        self::assertNotNull($manifest);
        self::assertSame(realpath($this->root) . '/build/out', $manifest->outputDir());
        self::assertSame(realpath($this->root) . '/tmp/gen', $manifest->cacheDir());
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
