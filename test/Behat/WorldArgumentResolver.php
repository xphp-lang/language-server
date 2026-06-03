<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Behat\Context\Argument\ArgumentResolver;
use Behat\Behat\EventDispatcher\Event\ExampleTested;
use Behat\Behat\EventDispatcher\Event\ScenarioTested;
use ReflectionClass;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Constructor-injects a per-scenario {@see World} into every context that
 * declares a `World` parameter.
 *
 * The resolver is a singleton, so it holds the current scenario's World and
 * resets it on each scenario/example start. Behat dispatches the BEFORE event
 * *before* it builds the scenario's context environment (setUp ->
 * isolateEnvironment -> ContextFactory::createContext, where resolvers run), so
 * reset() nulls the World first and resolveArguments() then lazily creates one
 * fresh World shared across that scenario's contexts.
 */
final class WorldArgumentResolver implements ArgumentResolver, EventSubscriberInterface
{
    private ?World $world = null;

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        // Fresh World per scenario AND per Scenario Outline example.
        return [
            ScenarioTested::BEFORE => 'reset',
            ExampleTested::BEFORE => 'reset',
        ];
    }

    public function reset(): void
    {
        $this->world = null;
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array<int|string, mixed>
     */
    public function resolveArguments(ReflectionClass $classReflection, array $arguments): array
    {
        $constructor = $classReflection->getConstructor();
        if ($constructor === null) {
            return $arguments;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === World::class) {
                $arguments[$parameter->getName()] = $this->world ??= new World();
            }
        }

        return $arguments;
    }
}
