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
#[Internal(reason: 'Architecture invariant shared by the runtime guard and the boundary/wiring checkers')]
final class CompositionRoots
{
    /**
     * Classes exempt by exact name.
     *
     * @var list<string>
     */
    public const array CLASSES = [
        'Pulsar\\Core\\Kernel',
        'Pulsar\\Console\\Application',
        'Pulsar\\Console\\Command\\OptimizeCommand',
        'Pulsar\\Console\\Command\\BuildCommand',
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
