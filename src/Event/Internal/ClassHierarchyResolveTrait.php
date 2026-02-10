<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal;

use function array_push;
use function array_values;
use function class_implements;
use function class_parents;

/**
 * Shared class-hierarchy resolution for listener providers.
 *
 * Resolves an event class to all matching classes (exact + parents + interfaces)
 * with per-instance caching.
 */
trait ClassHierarchyResolveTrait
{
    /**
     * Cache of resolved class hierarchies per event class.
     *
     * @var array<class-string, list<class-string>>
     */
    private array $classHierarchyCache = [];

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
            array_push($classes, ...array_values($parents));
        }

        $interfaces = class_implements($eventClass);

        if ($interfaces !== false) {
            array_push($classes, ...array_values($interfaces));
        }

        /** @var list<class-string> $classes */
        $this->classHierarchyCache[$eventClass] = $classes;

        return $classes;
    }
}
