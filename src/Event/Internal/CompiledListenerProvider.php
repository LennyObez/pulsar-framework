<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal;

use Override;
use Psr\Container\ContainerInterface;
use Pulsar\Api\Internal;
use Pulsar\Event\EventSubscriberInterface;
use Pulsar\Event\Exception\EventException;
use Pulsar\Event\ListenerProviderInterface;

use function array_keys;
use function array_push;
use function usort;

/**
 * Read-only listener provider that loads from a compiled event map.
 *
 * Resolves listener classes from the container at dispatch time.
 * Registration methods throw: use ListenerProvider for dynamic registration.
 */
#[Internal]
final class CompiledListenerProvider implements ListenerProviderInterface, ListenerMetadataProviderInterface
{
    use ClassHierarchyResolveTrait;

    /**
     * Cache of resolved callables per event class (avoids re-sort + re-resolve).
     *
     * @var array<class-string, list<callable>>
     */
    private array $sortedCache = [];

    /**
     * @param array<class-string, array{listeners: list<array{class: string, method: string, priority: int, moduleId: string}>, requiresEnvelope: bool, stormOverride: ?int, listenerModuleIds: list<string>}> $compiledMap
     */
    public function __construct(
        private readonly array $compiledMap,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * @return iterable<callable>
     */
    #[Override]
    public function getListenersForEvent(object $event): iterable
    {
        $eventClass = $event::class;

        if (isset($this->sortedCache[$eventClass])) {
            return $this->sortedCache[$eventClass];
        }

        $matchingClasses = $this->resolveMatchingClasses($eventClass);
        $allEntries = [];

        foreach ($matchingClasses as $class) {
            if (isset($this->compiledMap[$class])) {
                array_push($allEntries, ...$this->compiledMap[$class]['listeners']);
            }
        }

        if ($allEntries === []) {
            $this->sortedCache[$eventClass] = [];

            return [];
        }

        // Re-sort merged listeners by priority DESC
        usort($allEntries, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'];
        });

        $callables = [];

        foreach ($allEntries as $entry) {
            /** @var class-string $listenerClass */
            $listenerClass = $entry['class'];
            $method = $entry['method'];

            /** @var object $instance */
            $instance = $this->container->get($listenerClass);

            /** @var callable $callable */
            $callable = [$instance, $method];
            $callables[] = $callable;
        }

        $this->sortedCache[$eventClass] = $callables;

        return $callables;
    }

    /**
     * @throws EventException Always: compiled providers are read-only
     */
    #[Override]
    public function addListener(string $eventClass, callable $listener, int $priority = 0, string $moduleId = ''): void
    {
        throw EventException::invalidListener('cannot add listeners to a compiled provider (read-only)');
    }

    /**
     * @throws EventException Always: compiled providers are read-only
     */
    #[Override]
    public function addSubscriber(EventSubscriberInterface $subscriber, string $moduleId = ''): void
    {
        throw EventException::invalidListener('cannot add subscribers to a compiled provider (read-only)');
    }

    #[Override]
    public function listenerModuleIdsFor(string $eventClass): array
    {
        $matchingClasses = $this->resolveMatchingClasses($eventClass);
        $moduleIds = [];

        foreach ($matchingClasses as $class) {
            if (isset($this->compiledMap[$class])) {
                foreach ($this->compiledMap[$class]['listenerModuleIds'] as $id) {
                    $moduleIds[$id] = true;
                }
            }
        }

        return array_keys($moduleIds);
    }

    #[Override]
    public function stormOverrideFor(string $eventClass): ?int
    {
        $matchingClasses = $this->resolveMatchingClasses($eventClass);

        foreach ($matchingClasses as $class) {
            if (isset($this->compiledMap[$class]) && $this->compiledMap[$class]['stormOverride'] !== null) {
                return $this->compiledMap[$class]['stormOverride'];
            }
        }

        return null;
    }

    #[Override]
    public function requiresEnvelopeFor(string $eventClass): bool
    {
        $matchingClasses = $this->resolveMatchingClasses($eventClass);

        return array_any($matchingClasses, fn(string $class): bool => isset($this->compiledMap[$class]) && $this->compiledMap[$class]['requiresEnvelope']);
    }

    /**
     * Get all event classes in the compiled map.
     *
     * @return list<class-string>
     */
    public function compiledEventClasses(): array
    {
        /** @var list<class-string> */
        return array_keys($this->compiledMap);
    }

}
