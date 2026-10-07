<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use Pulsar\Api\CompositionRoots;

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
     * Roots this check recognises that {@see CompositionRoots} does not list.
     *
     * The authority is `Pulsar\Api\CompositionRoots`, and this used to be a
     * fourth copy of it rather than a supplement. It had already drifted the way
     * the three before it did: it named `BuildCommand` and not `OptimizeCommand`,
     * so one of the two commands that writes the production caches was exempt
     * from the cross-module rule and the other was not — a difference nobody
     * decided. `CompositionRootsAuthorityTest` catches a second definition in
     * `src`, `scripts` and `tools`, which is everywhere except here.
     *
     * Two entries are genuinely local to the static check and are kept:
     *
     * - `PreloadGenerator` — a preload manifest is a list of concrete classes to
     *   load, so naming them across module lines is the job, not coupling. It is
     *   not a composition root at runtime, so it does not belong in the authority.
     * - `CmsSecurityIntegration` — an extension's own wiring seam, outside the
     *   `Pulsar\Core\Wiring\` prefix the authority exempts.
     */
    private const array ADDITIONAL_ROOTS = [
        'Pulsar\Build\PreloadGenerator',
        'Pulsar\Extension\Cms\CmsSecurityIntegration',
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
     * Membership is asked of {@see CompositionRoots}, which is the single
     * definition every other consumer already reads — production and tooling
     * alike — and which exempts `Pulsar\Core\Wiring\*` and `Pulsar\Core\Boot\*`
     * by prefix. Listing roots one by one here is what let EventWiring and
     * ZeroTrustWiring drift out of the exemption long after they were written,
     * and what left OptimizeCommand out while BuildCommand was in. The exemption
     * belongs to the authority, not to a hand-maintained roster per checker.
     */
    public static function isCompositionRoot(string $fqcn): bool
    {
        return CompositionRoots::contains($fqcn)
            || in_array($fqcn, self::ADDITIONAL_ROOTS, true);
    }

    /**
     * Check if a class is in a Controller namespace segment.
     *
     * Excludes `Pulsar\Core\Controller\*`, for the reason {@see isView()} excludes
     * the `Pulsar\View` module: the name collides with the MVC role and the
     * contents are not it. That namespace holds the handler-invocation machinery
     * of ADR-0044 — `HandlerArgumentResolverInterface`, `HandlerSignature`,
     * `HandlerDescriptor`, `SealedArgument`, `ArgumentResolverChain` — contracts
     * and value objects describing how a controller's arguments are resolved.
     * Nothing in it handles a request, and depending on it is not a cross-module
     * reach into another module's controllers; it is how a resolver declares
     * itself to the mechanism that calls it, which every resolver outside
     * `Pulsar\Core` must be able to do.
     */
    public static function isController(string $fqcn): bool
    {
        if (str_starts_with($fqcn, 'Pulsar\\Core\\Controller\\')) {
            return false;
        }

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
