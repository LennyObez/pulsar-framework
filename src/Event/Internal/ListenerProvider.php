<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal;

use Closure;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Event\Attribute\StormOverride;
use Pulsar\Event\EnvelopeRequiredEvent;
use Pulsar\Event\EventSubscriberInterface;
use Pulsar\Event\ListenerProviderInterface;
use ReflectionClass;

use function array_keys;
use function array_push;
use function get_class;
use function is_array;
use function is_string;
use function usort;

/**
 * Priority-sorted listener registry with per-listener module IDs.
 *
 * Deterministic sort: priority DESC -> sequence ASC -> FQCN alphabetical ASC.
 * Results cached per event class and invalidated on registration.
 */
#[Internal]
final class ListenerProvider implements ListenerProviderInterface, ListenerMetadataProviderInterface
{
    use ClassHierarchyResolveTrait;
    /**
     * @var array<class-string, list<array{callable: callable, priority: int, sequence: int, fqcn: string, moduleId: string}>>
     */
    private array $listeners = [];

    private int $sequenceCounter = 0;

    /**
     * Cache of sorted callables per event class (invalidated on add).
     *
     * @var array<class-string, list<callable>>
     */
    private array $sortedCache = [];

    /**
     * Cache for storm overrides resolved via reflection.
     *
     * @var array<string, int|false>
     */
    private static array $stormOverrideCache = [];

    /**
     * Cache for envelope requirement resolved via reflection.
     *
     * @var array<string, bool>
     */
    private static array $envelopeRequiredCache = [];

    #[Override]
    public function addListener(string $eventClass, callable $listener, int $priority = 0, string $moduleId = ''): void
    {
        $fqcn = $this->resolveListenerFqcn($listener);

        $this->listeners[$eventClass][] = [
            'callable' => $listener,
            'priority' => $priority,
            'sequence' => $this->sequenceCounter++,
            'fqcn' => $fqcn,
            'moduleId' => $moduleId,
        ];

        // Only the sorted listener order changes on registration; an event
        // class's parent/interface hierarchy is intrinsic to the class itself
        // and never changes when a listener is added, so the hierarchy cache
        // must not be invalidated here (doing so forced needless re-resolution).
        $this->sortedCache = [];
    }

    #[Override]
    public function addSubscriber(EventSubscriberInterface $subscriber, string $moduleId = ''): void
    {
        foreach ($subscriber->getSubscribedEvents() as $eventClass => $config) {
            [$method, $priority] = $config;

            /** @var callable $callable */
            $callable = [$subscriber, $method];
            $this->addListener($eventClass, $callable, $priority, $moduleId);
        }
    }

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
            if (isset($this->listeners[$class])) {
                array_push($allEntries, ...$this->listeners[$class]);
            }
        }

        usort($allEntries, static function (array $a, array $b): int {
            // Priority DESC (higher priority first)
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }

            // Sequence ASC (earlier registration first)
            if ($a['sequence'] !== $b['sequence']) {
                return $a['sequence'] <=> $b['sequence'];
            }

            // FQCN alphabetical ASC
            return $a['fqcn'] <=> $b['fqcn'];
        });

        $callables = array_map(static fn(array $entry): callable => $entry['callable'], $allEntries);

        $this->sortedCache[$eventClass] = $callables;

        return $callables;
    }

    #[Override]
    public function listenerModuleIdsFor(string $eventClass): array
    {
        $matchingClasses = $this->resolveMatchingClasses($eventClass);
        $moduleIds = [];

        foreach ($matchingClasses as $class) {
            if (!isset($this->listeners[$class])) {
                continue;
            }

            foreach ($this->listeners[$class] as $entry) {
                if ($entry['moduleId'] !== '') {
                    $moduleIds[$entry['moduleId']] = true;
                }
            }
        }

        return array_keys($moduleIds);
    }

    #[Override]
    public function stormOverrideFor(string $eventClass): ?int
    {
        if (isset(self::$stormOverrideCache[$eventClass])) {
            $cached = self::$stormOverrideCache[$eventClass];

            return $cached === false ? null : $cached;
        }

        if (!class_exists($eventClass)) {
            self::$stormOverrideCache[$eventClass] = false;

            return null;
        }

        $ref = new ReflectionClass($eventClass);
        $attrs = $ref->getAttributes(StormOverride::class);

        if ($attrs === []) {
            self::$stormOverrideCache[$eventClass] = false;

            return null;
        }

        /** @var StormOverride $override */
        $override = $attrs[0]->newInstance();
        self::$stormOverrideCache[$eventClass] = $override->maxDepth;

        return $override->maxDepth;
    }

    #[Override]
    public function requiresEnvelopeFor(string $eventClass): bool
    {
        if (isset(self::$envelopeRequiredCache[$eventClass])) {
            return self::$envelopeRequiredCache[$eventClass];
        }

        if (!class_exists($eventClass)) {
            self::$envelopeRequiredCache[$eventClass] = false;

            return false;
        }

        $ref = new ReflectionClass($eventClass);

        // Check for #[RequiresEnvelope] attribute
        if ($ref->getAttributes(RequiresEnvelope::class) !== []) {
            self::$envelopeRequiredCache[$eventClass] = true;

            return true;
        }

        // Check for EnvelopeRequiredEvent interface
        $result = $ref->implementsInterface(EnvelopeRequiredEvent::class);
        self::$envelopeRequiredCache[$eventClass] = $result;

        return $result;
    }

    /**
     * Get all registered event classes (for compilation).
     *
     * @return list<class-string>
     */
    public function registeredEventClasses(): array
    {
        /** @var list<class-string> */
        return array_keys($this->listeners);
    }

    /**
     * Get raw listener entries for a specific event class (for compilation).
     *
     * @param class-string $eventClass
     * @return list<array{callable: callable, priority: int, sequence: int, fqcn: string, moduleId: string}>
     */
    public function rawListenersFor(string $eventClass): array
    {
        return $this->listeners[$eventClass] ?? [];
    }

    /**
     * Resolve the FQCN identifier for a listener callable.
     */
    private function resolveListenerFqcn(callable $listener): string
    {
        if ($listener instanceof Closure) {
            return 'Closure';
        }

        if (is_array($listener)) {
            /** @var array{0: object|string, 1: string} $listener */
            $class = is_string($listener[0]) ? $listener[0] : get_class($listener[0]);

            return $class . '::' . $listener[1];
        }

        if (is_string($listener)) {
            return $listener;
        }

        /** @var object&callable $listener */
        return get_class($listener) . '::__invoke';
    }

    /**
     * Reset static caches (for testing).
     */
    public static function resetStaticCaches(): void
    {
        self::$stormOverrideCache = [];
        self::$envelopeRequiredCache = [];
    }
}
