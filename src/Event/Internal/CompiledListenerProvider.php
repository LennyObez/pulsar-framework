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
use function array_merge;
use function array_unique;
use function array_values;
use function class_implements;
use function class_parents;
use function get_class;
use function usort;

/**
 * Read-only listener provider that loads from a compiled event map.
 *
 * Resolves listener classes from the container at dispatch time.
 * Registration methods throw — use ListenerProvider for dynamic registration.
 */
#[Internal]
final class CompiledListenerProvider implements ListenerProviderInterface, ListenerMetadataProviderInterface
{
    /**
     * Cache of resolved class hierarchies per event class.
     *
     * @var array<class-string, list<class-string>>
     */
    private array $classHierarchyCache = [];

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
        /** @var class-string $eventClass */
        $eventClass = get_class($event);

        $matchingClasses = $this->resolveMatchingClasses($eventClass);
        $allEntries = [];

        foreach ($matchingClasses as $class) {
            if (isset($this->compiledMap[$class])) {
                $allEntries = array_merge($allEntries, $this->compiledMap[$class]['listeners']);
            }
        }

        if ($allEntries === []) {
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

        return $callables;
    }

    /**
     * @throws EventException Always — compiled providers are read-only
     */
    #[Override]
    public function addListener(string $eventClass, callable $listener, int $priority = 0, string $moduleId = ''): void
    {
        throw EventException::invalidListener('cannot add listeners to a compiled provider (read-only)');
    }

    /**
     * @throws EventException Always — compiled providers are read-only
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
                $moduleIds = array_merge($moduleIds, $this->compiledMap[$class]['listenerModuleIds']);
            }
        }

        return array_values(array_unique($moduleIds));
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

        foreach ($matchingClasses as $class) {
            if (isset($this->compiledMap[$class]) && $this->compiledMap[$class]['requiresEnvelope']) {
                return true;
            }
        }

        return false;
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

    /**
     * Resolve all matching class names for an event (exact + parents + interfaces).
     *
     * @param class-string $eventClass
     * @return list<class-string>
     */
    private function resolveMatchingClasses(string $eventClass): array
    {
        if (isset($this->classHierarchyCache[$eventClass])) {
            return $this->classHierarchyCache[$eventClass];
        }

        $classes = [$eventClass];

        $parents = class_parents($eventClass);

        if ($parents !== false) {
            /** @var list<class-string> $parentList */
            $parentList = array_values($parents);
            $classes = array_merge($classes, $parentList);
        }

        $interfaces = class_implements($eventClass);

        if ($interfaces !== false) {
            /** @var list<class-string> $interfaceList */
            $interfaceList = array_values($interfaces);
            $classes = array_merge($classes, $interfaceList);
        }

        /** @var list<class-string> $classes */
        $this->classHierarchyCache[$eventClass] = $classes;

        return $classes;
    }
}
