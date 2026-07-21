<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Reflection;

use Closure;
use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServer\Event\TextDocumentUpdated;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServerProtocol\VersionedTextDocumentIdentifier;
use Phpactor\WorseReflection\Core\Cache;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\ReflectionCachePurger;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class ReflectionCachePurgerTest extends TestCase
{
    public function testListensOnlyForTextDocumentUpdated(): void
    {
        $purger = new ReflectionCachePurger($this->spyCache());

        $listeners = $purger->getListenersForEvent(new \stdClass());
        self::assertSame([], is_array($listeners) ? $listeners : iterator_to_array($listeners));

        $listeners = $purger->getListenersForEvent($this->updated('file:///X.xphp'));
        $listenerList = is_array($listeners) ? $listeners : iterator_to_array($listeners);
        self::assertCount(1, $listenerList);

        $listener = $listenerList[0];
        self::assertIsArray($listener);
        self::assertCount(2, $listener);
        self::assertSame($purger, $listener[0]);
        self::assertSame('purge', $listener[1]);
        self::assertTrue(is_callable($listener), 'listener must be callable as-is');
    }

    public function testPurgeFlushesTheCache(): void
    {
        $cache = $this->spyCache();
        $purger = new ReflectionCachePurger($cache);

        self::assertSame(0, $cache->purges);
        $purger->purge($this->updated('file:///X.xphp'));
        self::assertSame(1, $cache->purges, 'a didChange must flush the reflection cache exactly once');
    }

    public function testEditToOpenBufferIsVisibleAfterPurge(): void
    {
        // End-to-end regression for the staleness the cache would otherwise
        // introduce: reflect a class (populating the name-keyed cache), then
        // edit the open buffer to add a method, fire the purger, and confirm
        // the re-reflection sees the new member.  Without the purger the
        // second reflection would return the pre-edit (cached) view.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            'file:///Coll.xphp',
            'xphp',
            1,
            "<?php\nnamespace App;\nclass Coll { public function alpha(): void {} }\n",
        ));

        $factory = $this->factory($workspace);
        $reflector = $factory->build();
        $purger = new ReflectionCachePurger($factory->reflectionCache());

        self::assertSame(['alpha'], $this->methodNames($reflector, 'App\\Coll'));

        // Edit the open buffer: add `beta()`.  Workspace::update bumps the
        // document version so the source locator returns the new text; the
        // reflection cache, however, is keyed only by name and still holds
        // the pre-edit reflection until purged.
        $newText = "<?php\nnamespace App;\nclass Coll { public function alpha(): void {} public function beta(): void {} }\n";
        $workspace->update(new VersionedTextDocumentIdentifier(2, 'file:///Coll.xphp'), $newText);

        $purger->purge($this->updated('file:///Coll.xphp', 2, $newText));

        self::assertSame(['alpha', 'beta'], $this->methodNames($reflector, 'App\\Coll'));
    }

    /**
     * @return list<string>
     */
    private function methodNames(\Phpactor\WorseReflection\Reflector $reflector, string $fqn): array
    {
        $names = [];
        foreach ($reflector->reflectClass($fqn)->methods() as $method) {
            $names[] = (string) $method->name();
        }
        sort($names);
        return $names;
    }

    private function updated(string $uri, int $version = 1, string $text = ''): TextDocumentUpdated
    {
        return new TextDocumentUpdated(new VersionedTextDocumentIdentifier($version, $uri), $text);
    }

    private function factory(PhpactorWorkspace $workspace): ReflectorFactory
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $root = sys_get_temp_dir() . '/xphp-purger-empty';
        if (!is_dir($root)) {
            mkdir($root, 0o755, true);
        }
        return new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            $root,
            '', // no stubs -- this test only exercises workspace reflection
            ReflectorFactory::defaultCacheDir(),
            new FqnIndex($workspace, $cache, $parser, $root),
        );
    }

    /**
     * @return Cache&object{purges: int}
     */
    private function spyCache(): Cache
    {
        return new class implements Cache {
            public int $purges = 0;

            public function getOrSet(string $key, Closure $closure)
            {
                return $closure();
            }

            public function purge(): void
            {
                $this->purges++;
            }
        };
    }
}
