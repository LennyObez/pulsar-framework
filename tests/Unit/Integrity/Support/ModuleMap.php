<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use function in_array;
use function preg_match;
use function str_contains;
use function str_starts_with;

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
    /**
     * Classes that are composition roots and exempt from cross-module internal
     * import rules.
     *
     * Wirings are NOT listed here: `Pulsar\Core\Wiring\*` is exempt by prefix in
     * {@see self::isCompositionRoot()}, so a newly added wiring is covered the day
     * it is written. This list is only for roots outside that namespace.
     */
    private const array COMPOSITION_ROOTS = [
        'Pulsar\Core\Kernel',
        'Pulsar\Core\Boot\BuildArtifactVerifier',
        'Pulsar\Core\Boot\CachedRouteReconstructor',
        'Pulsar\Core\Boot\ExtensionDiscovery',
        'Pulsar\Core\Boot\ExtensionViewPathRegistrar',
        'Pulsar\Core\Boot\ProjectRouteLoader',
        'Pulsar\Console\Application',
        'Pulsar\Console\Command\BuildCommand',
        // Build-time class enumerator: a preload manifest is a list of concrete
        // classes to load, so naming them across module lines is the job itself,
        // not accidental coupling — the same reason BuildCommand is listed.
        'Pulsar\Build\PreloadGenerator',
        'Pulsar\Extension\Cms\CmsSecurityIntegration',
        'Pulsar\Core\MicroKernel',
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
     *
     * Every `Pulsar\Core\Wiring\*` class qualifies by prefix, matching how the
     * runtime {@see \Pulsar\Container\Internal\BoundaryGuard} exempts them. Listing
     * wirings one by one here is what let EventWiring and ZeroTrustWiring drift out
     * of the exemption and fail this rule long after they were written: wiring a
     * module's internals into the container is the entire purpose of a wiring, so
     * the exemption belongs to the namespace, not to a hand-maintained roster.
     */
    public static function isCompositionRoot(string $fqcn): bool
    {
        return str_starts_with($fqcn, 'Pulsar\\Core\\Wiring\\')
            || in_array($fqcn, self::COMPOSITION_ROOTS, true);
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
     *
     * Excludes the top-level Pulsar\View module (template engine),
     * which is a standalone module, not an MVC view within another module.
     */
    public static function isView(string $fqcn): bool
    {
        // The Pulsar\View module is the template engine — not an MVC view
        if (str_starts_with($fqcn, 'Pulsar\\View\\')) {
            return false;
        }

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
