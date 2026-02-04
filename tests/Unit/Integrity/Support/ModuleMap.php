<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use function in_array;
use function preg_match;
use function str_contains;

/**
 * Maps fully-qualified class names to their module identity.
 *
 * Module identity format:
 * - Core modules:      "core:{Module}"   e.g. "core:Auth", "core:Http"
 * - Extensions:        "ext:{Name}"      e.g. "ext:Payments", "ext:Example"
 * - Non-Pulsar:        null
 */
final class ModuleMap
{
    /** Classes that are composition roots and exempt from cross-module internal import rules. */
    private const array COMPOSITION_ROOTS = [
        'Pulsar\Core\Kernel',
        'Pulsar\Core\Wiring\ServiceWiringInterface',
        'Pulsar\Core\Wiring\AuthWiring',
        'Pulsar\Core\Wiring\ConfigWiring',
        'Pulsar\Core\Wiring\DatabaseWiring',
        'Pulsar\Core\Wiring\DeployWiring',
        'Pulsar\Core\Wiring\DiagnosticsWiring',
        'Pulsar\Core\Wiring\ErrorTrackingWiring',
        'Pulsar\Core\Wiring\ExceptionHandlerWiring',
        'Pulsar\Core\Wiring\FeatureFlagWiring',
        'Pulsar\Core\Wiring\IntegrityWiring',
        'Pulsar\Core\Wiring\LoggingWiring',
        'Pulsar\Core\Wiring\MetricsWiring',
        'Pulsar\Core\Wiring\QueueWiring',
        'Pulsar\Core\Wiring\RequestContextWiring',
        'Pulsar\Core\Wiring\ResilienceWiring',
        'Pulsar\Core\Wiring\RuntimeWiring',
        'Pulsar\Core\Wiring\SchedulerWiring',
        'Pulsar\Core\Wiring\SecurityWiring',
        'Pulsar\Core\Wiring\SupervisorWiring',
        'Pulsar\Core\Wiring\TenancyWiring',
        'Pulsar\Core\Wiring\TracingWiring',
        'Pulsar\Console\Application',
    ];

    /**
     * Determine which module a fully-qualified class name belongs to.
     *
     * @return non-empty-string|null Module identity or null for non-Pulsar classes
     */
    public static function moduleFor(string $fqcn): ?string
    {
        // Extension: Pulsar\Extension\{Name}\...
        if (preg_match('/^Pulsar\\\\Extension\\\\([^\\\\]+)/', $fqcn, $matches) === 1) {
            return 'ext:' . $matches[1];
        }

        // Core: Pulsar\{Module}\...
        if (preg_match('/^Pulsar\\\\([^\\\\]+)/', $fqcn, $matches) === 1) {
            return 'core:' . $matches[1];
        }

        return null;
    }

    /**
     * Check if two classes belong to the same module.
     */
    public static function sameModule(string $classA, string $classB): bool
    {
        $moduleA = self::moduleFor($classA);
        $moduleB = self::moduleFor($classB);

        return $moduleA !== null && $moduleA === $moduleB;
    }

    /**
     * Check if a class is a composition root (exempt from cross-module internal rules).
     */
    public static function isCompositionRoot(string $fqcn): bool
    {
        return in_array($fqcn, self::COMPOSITION_ROOTS, true);
    }

    /**
     * Check if a class is in a Controller namespace segment.
     */
    public static function isController(string $fqcn): bool
    {
        return str_contains($fqcn, '\\Controller\\')
            || str_contains($fqcn, '\\Controller');
    }

    /**
     * Check if a class is in a View namespace segment.
     */
    public static function isView(string $fqcn): bool
    {
        return str_contains($fqcn, '\\View\\')
            || str_contains($fqcn, '\\View');
    }

    /**
     * Check if a class is in a Contract or Contracts namespace segment.
     */
    public static function isContract(string $fqcn): bool
    {
        return str_contains($fqcn, '\\Contract\\')
            || str_contains($fqcn, '\\Contracts\\');
    }

    /**
     * Check if a class is in an Adapter or Provider namespace segment.
     */
    public static function isAdapter(string $fqcn): bool
    {
        return str_contains($fqcn, '\\Adapter\\')
            || str_contains($fqcn, '\\Provider\\');
    }
}
