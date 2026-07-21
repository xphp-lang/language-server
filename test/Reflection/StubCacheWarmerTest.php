<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Reflection;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServer\Event\Initialized;
use Phpactor\LanguageServerProtocol\ClientCapabilities;
use Phpactor\LanguageServerProtocol\InitializeParams;
use Phpactor\WorseReflection\Reflector;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Reflection\StubCacheWarmer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\call;
use function Amp\Promise\wait;

final class StubCacheWarmerTest extends TestCase
{
    public function testListensOnlyForInitializedEvent(): void
    {
        $warmer = new StubCacheWarmer($this->reflector(stubs: false));

        // Unrecognised event -> no listeners.
        $listeners = $warmer->getListenersForEvent(new \stdClass());
        self::assertSame([], is_array($listeners) ? $listeners : iterator_to_array($listeners));

        // Initialized -> exactly one `[$warmer, 'warm']` listener.  Asserting
        // the shape (not just the count) kills an ArrayItemRemoval mutant on
        // `[[$this, 'warm']]` -> `[['warm']]`, which keeps the count at 1.
        $listeners = $warmer->getListenersForEvent($this->initialized());
        $listenerList = is_array($listeners) ? $listeners : iterator_to_array($listeners);
        self::assertCount(1, $listenerList);

        $listener = $listenerList[0];
        self::assertIsArray($listener);
        self::assertCount(2, $listener);
        self::assertSame($warmer, $listener[0]);
        self::assertSame('warm', $listener[1]);
        self::assertTrue(is_callable($listener), 'listener must be callable as-is');
    }

    public function testWarmBuildsTheStubMap(): void
    {
        // Prove the WARMER drives the stub map build -- not merely that a map
        // happens to exist in the shared durable cache from a prior run.  Use
        // a tiny scratch stub dir (one function) + a fresh cache dir so the
        // map is provably ABSENT before warming and PRESENT after, and the
        // build is cheap (no ~512M phpstorm-stubs walk that would risk an OOM
        // under the unit-test memory limit).
        $stubDir = sys_get_temp_dir() . '/xphp-stubwarm-stubs-' . bin2hex(random_bytes(6));
        $cacheDir = sys_get_temp_dir() . '/xphp-stubwarm-cache-' . bin2hex(random_bytes(6));
        mkdir($stubDir, 0o755, true);
        file_put_contents($stubDir . '/functions.php', "<?php\nfunction strlen(string \$s): int {}\n");

        $mapFile = $cacheDir . '/' . md5($stubDir) . '.map';

        try {
            $reflector = $this->reflectorWith(stubPath: $stubDir, cacheDir: $cacheDir);
            $warmer = new StubCacheWarmer($reflector);

            self::assertFileDoesNotExist($mapFile, 'precondition: the stub map must not exist yet');

            wait(call(function () use ($warmer): \Generator {
                $warmer->warm($this->initialized());
                // Let asyncCall's enqueued callback run.
                yield new \Amp\Delayed(10);
            }));

            self::assertFileExists($mapFile, 'warming must build and serialize the stub map');
        } finally {
            $this->rmrf($stubDir);
            if (is_dir($cacheDir)) {
                $this->rmrf($cacheDir);
            }
        }
    }

    public function testWarmSwallowsReflectionFailures(): void
    {
        // A stubs-less reflector can't resolve `strlen`, so reflectFunction
        // throws.  The async warm body must swallow it -- warming is
        // best-effort and can never destabilise the session.  Reaching the
        // assertion without an uncaught exception IS the test.
        $warmer = new StubCacheWarmer($this->reflector(stubs: false));

        wait(call(function () use ($warmer): \Generator {
            $warmer->warm($this->initialized());
            yield new \Amp\Delayed(10);
        }));

        $this->addToAssertionCount(1);
    }

    private function initialized(): Initialized
    {
        return new Initialized(new InitializeParams(new ClientCapabilities()));
    }

    private function reflector(bool $stubs): Reflector
    {
        return $this->reflectorWith(
            stubPath: $stubs ? ReflectorFactory::defaultStubPath() : '',
            cacheDir: ReflectorFactory::defaultCacheDir(),
        );
    }

    private function reflectorWith(string $stubPath, string $cacheDir): Reflector
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $workspace = new PhpactorWorkspace();
        $root = sys_get_temp_dir() . '/xphp-stubwarm-empty';
        if (!is_dir($root)) {
            mkdir($root, 0o755, true);
        }
        return (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            $root,
            $stubPath,
            $cacheDir,
            new FqnIndex($workspace, $cache, $parser, $root),
        ))->build();
    }

    private function rmrf(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            is_dir($p) ? $this->rmrf($p) : unlink($p);
        }
        rmdir($dir);
    }
}
