<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Behat\Context\ServiceContainer\ContextExtension;
use Behat\Testwork\EventDispatcher\ServiceContainer\EventDispatcherExtension;
use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Registers {@see WorldArgumentResolver} so the per-scenario {@see World} can be
 * constructor-injected into every context, and so it receives the
 * scenario/example BEFORE events that reset it.
 *
 * Enabled via behat.dist.yml:
 *   default:
 *     extensions:
 *       XPHP\Lsp\Test\Behat\WorldExtension: ~
 */
final class WorldExtension implements Extension
{
    private const RESOLVER_ID = 'xphp.world.argument_resolver';

    public function getConfigKey(): string
    {
        return 'xphp_world';
    }

    public function initialize(ExtensionManager $extensionManager): void
    {
    }

    public function configure(ArrayNodeDefinition $builder): void
    {
    }

    public function load(ContainerBuilder $container, array $config): void
    {
        $definition = new Definition(WorldArgumentResolver::class);
        // Inject the World into context constructors...
        $definition->addTag(ContextExtension::ARGUMENT_RESOLVER_TAG);
        // ...and reset it before each scenario / outline example.
        $definition->addTag(EventDispatcherExtension::SUBSCRIBER_TAG);
        $container->setDefinition(self::RESOLVER_ID, $definition);
    }

    public function process(ContainerBuilder $container): void
    {
    }
}
