<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use NoDiscard;

use function class_exists;
use function interface_exists;
use function is_a;

/**
 * The one definition of "this object can hand you another service".
 *
 * {@see ServiceRestrictionMap} classifies by service ID, and an ID is not a
 * security property: the composition root decides what an ID resolves to, so
 * `Pulsar\Container\ContainerInterface` on a safe-list meant "safe" for an ID
 * the Kernel binds to the real container. Every service that can produce
 * another service is the same hole under a different name, and there is no
 * ID-shaped rule that catches the next one.
 *
 * So the sandbox classifies the VALUE as well, on every path out of the scope.
 * A value that reaches another service gets one of three answers:
 *
 *  - It has a scoped equivalent — a container or a router. The proxy hands back
 *    ITS OWN scoped version ({@see self::CONTAINER_ROOTS},
 *    {@see self::ROUTER_ROOTS}); the extension gets something with the same
 *    contract and the same tier it already had, so legitimate code that
 *    type-hints a container keeps working and gains nothing by doing so.
 *  - It has no scoped equivalent but it HAS A PRICE — the middleware pipeline,
 *    the config repository. These are listed in {@see self::REFUSED_ROOTS} AND
 *    in {@see ServiceRestrictionMap}'s restricted map, and
 *    {@see ScopedContainerProxy::contain()} lets a tier that holds the mapped
 *    capability through. That is not a loophole in the refusal, it is the
 *    refusal being honest: ADR-0023 promises Verified the `ConfigWrite` and
 *    `MiddlewareRegister` capabilities, and a capability that its holder cannot
 *    exercise is a claim rather than a control.
 *  - It has no scoped equivalent and no price — the kernel, the extension
 *    bootstrap, the extension registry, a wiring. Nothing can safely wrap those
 *    and no grant unlocks them, so they are refused outright.
 *
 * Roots are declared as strings, not imported class names, on purpose:
 * `instanceof` against a string neither autoloads nor requires the class to
 * exist, so this list can name types from modules Extensibility must not
 * depend on, and stays correct in a build where one of them is absent.
 *
 * @internal Not part of the public API
 */
final readonly class SandboxReach
{
    /**
     * Containers. Reaching one yields the extension's own scoped container.
     *
     * Both the PSR contract and Pulsar's extension of it: an extension may hold
     * either type, and a raw container satisfies both.
     */
    public const array CONTAINER_ROOTS = [
        'Psr\Container\ContainerInterface',
        'Pulsar\Container\ContainerInterface',
        'Pulsar\Container\AdvancedContainerInterface',
    ];

    /** Routers. Reaching one yields the extension's own scoped router. */
    public const array ROUTER_ROOTS = [
        'Pulsar\Routing\RouterInterface',
    ];

    /**
     * Types that hand out services and cannot be scoped.
     *
     * An entry here is refused unless {@see ServiceRestrictionMap} names a
     * capability for it and the extension holds that capability — see the class
     * docblock. Most carry no price at all and are refused at every tier below
     * Core.
     *
     * Each entry is here because holding one is equivalent to holding the
     * container, or better:
     *
     *  - `KernelInterface` owns the container and the router and can re-boot.
     *  - `ExtensionBootstrap` owns `capabilityPolicy` and
     *    `trustedExtensionsConfig` as PUBLIC settable properties: an extension
     *    holding it switches the sandbox off for every extension scoped after
     *    it.
     *  - `ExtensionCatalogInterface` (and the writable `ExtensionRegistry`
     *    behind it) hands out every other extension instance, and its writable
     *    half lets an extension `add()` an accomplice under a name the host
     *    trusts at Core, or `setState()` a security extension to Failed so it
     *    never boots.
     *  - `ServiceWiringInterface` is a composition-root fragment: obtaining one
     *    from inside a request is a sign the composition root has leaked, and
     *    the wiring list is what an extension would rewrite to change the
     *    framework's own bindings.
     *  - `ConfigManagerInterface` / `ConfigRepository` reach the whole
     *    configuration, including the security settings the tier depends on.
     *    Priced at `ConfigWrite`, for the same reason as the pipeline.
     *  - `MiddlewarePipelineInterface` / `MiddlewareRegistry` install code in
     *    front of every request. These are the `MiddlewareRegister` capability's
     *    subject and its only enforcement site: the restriction map prices both
     *    at `MiddlewareRegister`, so Core and Verified — the tiers ADR-0023's
     *    table grants it to — can register global middleware, and Community and
     *    Untrusted cannot. Before that pricing existed the capability was
     *    granted to Verified and refused to it by this list, which is the
     *    difference between a control and a table. `PostRoutingPipeline` needs
     *    no entry of its own: it implements the pipeline interface, and matching
     *    is subtype-aware.
     *  - `ArgumentResolverRegistryInterface` decides what every controller
     *    argument resolves to.
     *
     * One type that belongs here by nature is deliberately absent, and by
     * extension anything shaped like it: `Core\Boot\DeferredComposition`, which
     * runs composition-root callbacks with the real container. Naming it would
     * make the extension sandbox depend on the Kernel's private boot
     * machinery, which the architecture rules forbid for the same reason this
     * class exists — a module reaching into another module's internals. The
     * rules scan class-name STRINGS as well as imports, precisely so that this
     * kind of indirection cannot slip past them. Nothing is lost: an
     * unclassified id is already denied by default, so they were never
     * reachable through {@see ScopedContainerProxy::get()} in the first place,
     * and this list is defence for the case where a safe-listed id is bound to
     * something it should not be.
     *
     * @var list<class-string|string>
     */
    public const array REFUSED_ROOTS = [
        'Pulsar\Core\KernelInterface',
        'Pulsar\Extensibility\ExtensionBootstrap',
        'Pulsar\Extensibility\ExtensionCatalogInterface',
        'Pulsar\Core\Wiring\ServiceWiringInterface',
        'Pulsar\Config\ConfigManagerInterface',
        'Pulsar\Config\ConfigRepository',
        'Pulsar\Http\Middleware\MiddlewarePipelineInterface',
        'Pulsar\Http\Middleware\MiddlewareRegistry',
        'Pulsar\Core\Controller\ArgumentResolverRegistryInterface',
    ];

    /**
     * Every reach-capable root, scoped and refused alike.
     *
     * Used by {@see SandboxReachAnalyzer} to decide whether a type's declared
     * surface leads anywhere it should not, and by the test that keeps
     * {@see ServiceRestrictionMap}'s safe-list honest.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public static function allRoots(): array
    {
        return [
            ...self::CONTAINER_ROOTS,
            ...self::ROUTER_ROOTS,
            ...self::REFUSED_ROOTS,
        ];
    }

    /**
     * The reach-capable root `$type` is, or descends from, if any.
     *
     * Subtype-aware in both directions: a concrete `Container` IS a
     * `ContainerInterface` (is_a), and a type named as the root itself matches
     * by name even when the class is absent from this build. An interface that
     * a root merely EXTENDS is not a root — holding `Countable` is not holding
     * a router.
     */
    #[NoDiscard]
    public static function rootFor(string $type): ?string
    {
        foreach (self::allRoots() as $root) {
            if ($type === $root) {
                return $root;
            }

            if ((class_exists($type) || interface_exists($type)) && is_a($type, $root, true)) {
                return $root;
            }
        }

        return null;
    }

    /**
     * The refused root this value is an instance of, or null when it is not one.
     */
    #[NoDiscard]
    public static function refusedRootFor(object $value): ?string
    {
        foreach (self::REFUSED_ROOTS as $root) {
            if ($value instanceof $root) {
                return $root;
            }
        }

        return null;
    }

    /**
     * Whether this value is a container the proxy should re-wrap.
     */
    #[NoDiscard]
    public static function isContainer(object $value): bool
    {
        foreach (self::CONTAINER_ROOTS as $root) {
            if ($value instanceof $root) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this value is a router the proxy should re-wrap.
     */
    #[NoDiscard]
    public static function isRouter(object $value): bool
    {
        foreach (self::ROUTER_ROOTS as $root) {
            if ($value instanceof $root) {
                return true;
            }
        }

        return false;
    }
}
