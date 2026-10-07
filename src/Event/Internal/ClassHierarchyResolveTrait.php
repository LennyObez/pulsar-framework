<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal;

use function array_push;
use function array_values;
use function class_exists;
use function class_implements;
use function class_parents;
use function interface_exists;

/**
 * Shared class-hierarchy resolution for listener providers.
 *
 * Resolves an event key to all matching keys (exact + parents + interfaces)
 * with per-instance caching. The key is normally an event class name, but
 * envelope dispatch uses a logical event-type string (e.g. "order.placed");
 * such a key has no class hierarchy and resolves to itself.
 */
trait ClassHierarchyResolveTrait
{
    /**
     * Cache of resolved hierarchies per event key.
     *
     * @var array<string, list<string>>
     */
    private array $classHierarchyCache = [];

    /**
     * Resolve all matching keys for an event (exact + parents + interfaces).
     *
     * @return list<string>
     */
    private function resolveMatchingClasses(string $eventClass): array
    {
        if (isset($this->classHierarchyCache[$eventClass])) {
            return $this->classHierarchyCache[$eventClass];
        }

        $classes = [$eventClass];

        // Only walk the class graph for real classes/interfaces. A logical
        // event-type string (e.g. "order.placed") is not loadable, and calling
        // class_parents()/class_implements() on it would emit a warning and
        // return false; it correctly resolves to just itself.
        if (class_exists($eventClass) || interface_exists($eventClass)) {
            $parents = class_parents($eventClass);

            if ($parents !== false) {
                array_push($classes, ...array_values($parents));
            }

            $interfaces = class_implements($eventClass);

            if ($interfaces !== false) {
                array_push($classes, ...array_values($interfaces));
            }
        }

        $this->classHierarchyCache[$eventClass] = $classes;

        return $classes;
    }
}
