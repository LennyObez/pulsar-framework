<?php

declare(strict_types=1);

namespace Pulsar\Api;

use function in_array;
use function str_starts_with;

/**
 * The one place that decides what counts as a composition root.
 *
 * A composition root is where cross-module assembly legitimately happens — a kernel,
 * the boot sequence, the wiring classes, the console commands that build things. The
 * boundary rules exempt it, because forbidding a wiring class from touching two modules
 * would forbid wiring.
 *
 * This existed three times: once in the runtime guard, once in the static boundary
 * checker, once in the wiring checker. They had already drifted, and not only in shape:
 *
 * - `Pulsar\Core\Boot\` was a root for the static checker and **not** for the runtime
 *   guard, so a class there passed the gate and would have been refused when it ran.
 *   It is a root — boot is assembly — so the unified answer includes it, which widens
 *   the runtime guard by exactly that namespace.
 * - The runtime guard held the `Core\Wiring\` prefix inside its list of exact class
 *   names and matched every entry with str_starts_with(). That made `Pulsar\Core\Kernel`
 *   match `Pulsar\Core\KernelHandler` too — an exemption nobody wrote down. Exact names
 *   are now compared exactly, and only prefixes are matched as prefixes.
 *
 * Three copies of a rule are three rules. This one is imported by all its consumers,
 * production and tooling alike, and a guard test fails the build if a second definition
 * appears.
 */
// Published, not internal. The docblock above says this is imported by every
// consumer, production and tooling alike, and the runtime guard in
// Container\Internal is one of them — so #[Internal] forbade the single use the
// class exists for. What it lists is an architecture contract: an extension
// author writing a wiring class needs to know what counts as a root, and moving
// a name in or out of these lists changes what the boundary rules permit.
#[Api(since: '1.0.0')]
final class CompositionRoots
{
    /**
     * Classes exempt by exact name.
     *
     * @var list<string>
     */
    public const array CLASSES = [
        'Pulsar\\Core\\Kernel',
        // Kernel's sibling: it assembles the same graph with fewer parts, so
        // exempting one and not the other exempted an implementation detail
        // rather than a role.
        'Pulsar\\Core\\MicroKernel',
        'Pulsar\\Console\\Application',
        'Pulsar\\Console\\Command\\OptimizeCommand',
        'Pulsar\\Console\\Command\\BuildCommand',
        // Builds the route cache, which is the same kind of work OptimizeCommand
        // and BuildCommand already do here.
        'Pulsar\\Console\\Command\\RoutesCacheCommand',
        // Reports the wiring graph, so it must read the list the graph is built
        // from. Inspecting assembly needs the same reach as performing it.
        'Pulsar\\Console\\Command\\DebugWiringCommand',
        // A wiring class that happens to live outside Core\Wiring\. The exemption
        // follows the role, not the address.
        'Pulsar\\Api\\OpenApi\\OpenApiWiring',
        // Boot is assembly, which is why Core\Boot\ is exempt below. This one
        // boots the development server from outside that namespace.
        'Pulsar\\Dev\\DevServerBootstrap',
    ];

    /**
     * Namespaces whose every class is exempt.
     *
     * @var list<string>
     */
    public const array NAMESPACES = [
        'Pulsar\\Core\\Wiring\\',
        'Pulsar\\Core\\Boot\\',
    ];

    public static function contains(string $fqcn): bool
    {
        if (in_array($fqcn, self::CLASSES, true)) {
            return true;
        }

        foreach (self::NAMESPACES as $namespace) {
            if (str_starts_with($fqcn, $namespace)) {
                return true;
            }
        }

        return false;
    }
}
