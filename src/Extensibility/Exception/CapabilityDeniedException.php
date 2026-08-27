<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;

use function rtrim;
use function sprintf;
use function ucfirst;

/**
 * Thrown when an extension attempts an operation denied by its trust tier.
 *
 * Error messages include the denied capability, the extension's effective tier,
 * and remediation guidance for the host application.
 * @api
 */
#[Api(since: '1.0.0')]
final class CapabilityDeniedException extends ExtensionException
{
    /**
     * Denied access to a specific container service.
     */
    #[NoDiscard]
    public static function forService(
        string $serviceId,
        TrustTier $tier,
        ExtensionCapability $required,
    ): self {
        return new self(sprintf(
            'Cannot resolve service "%s": %s tier does not have %s capability. '
            . 'Grant this capability in config/extensions.php by adding '
            . "'additional_capabilities' => ['%s'].",
            $serviceId,
            ucfirst($tier->value),
            $required->name,
            $required->name,
        ));
    }

    /**
     * Denied a container operation (bind/instance).
     */
    #[NoDiscard]
    public static function forCapability(
        TrustTier $tier,
        ExtensionCapability $denied,
    ): self {
        return new self(sprintf(
            '%s tier does not have %s capability. '
            . 'Elevate the extension tier or grant the capability in config/extensions.php.',
            ucfirst($tier->value),
            $denied->name,
        ));
    }

    /**
     * Denied a service whose resolution would hand the extension the very
     * objects the sandbox mediates — the container or the router themselves.
     *
     * Deliberately NOT phrased as a missing capability: there is no grant that
     * unlocks this and no entry an operator can add to make it resolve. An
     * extension holding the real container is an extension with no tier at all,
     * so the message points at the design instead of at a config key.
     */
    #[NoDiscard]
    public static function forSandboxEscape(
        string $serviceId,
        TrustTier $tier,
    ): self {
        return new self(sprintf(
            'Cannot resolve service "%s" from a %s tier extension: it resolves to the '
            . 'container or router that the capability sandbox mediates, so resolving it '
            . 'would bypass every other check. No capability grant unlocks this. '
            . 'Depend on the scoped container and router passed to register()/boot() instead.',
            $serviceId,
            ucfirst($tier->value),
        ));
    }

    /**
     * Denied because the VALUE that came back hands out services.
     *
     * The companion to {@see self::forSandboxEscape()}, which judges the ID
     * asked for. This one judges what arrived: an ID says nothing about what
     * the composition root bound to it, so a service classified as safe can
     * still resolve to the kernel, the extension bootstrap, a wiring or a
     * service provider. Containers and routers are re-scoped rather than
     * refused; everything on this path has no scoped equivalent to hand back.
     *
     * @param string $origin Where the value came from, in words the extension
     *                       author can act on ("service \"x\"", "the factory
     *                       bound for \"y\"")
     * @param string $rootType The reach-capable type the value turned out to be
     */
    #[NoDiscard]
    public static function forReachableValue(
        string $origin,
        string $rootType,
        TrustTier $tier,
    ): self {
        return new self(sprintf(
            'Refusing to hand %s to a %s tier extension: it is a %s, which can hand out '
            . 'any other service and so would bypass every capability check. '
            . 'No capability grant unlocks this.',
            $origin,
            ucfirst($tier->value),
            $rootType,
        ));
    }

    /**
     * Denied because autowiring the named class would inject a service-dispenser.
     *
     * Binding a class NAME hands the CONTAINER the job of choosing that
     * constructor's arguments, and the container building it is the real one —
     * so `bind('x', Mine::class)` with `Mine::__construct(ContainerInterface $c)`
     * used to deliver the unscoped container to extension code without any
     * capability being consulted. The same is true of an extension's service
     * provider, which the bootstrap instantiates through the container.
     *
     * The remedy is in the message because it costs the author nothing: a
     * factory closure registered through the scoped container is invoked WITH
     * that scoped container, so `bind('x', fn($c) => new Mine($c))` keeps the
     * dependency and keeps the tier.
     *
     * @param string $reachPath How the root is reached, as
     *                          {@see \Pulsar\Extensibility\Internal\SandboxReachAnalyzer}
     *                          reports it
     */
    #[NoDiscard]
    public static function forAutowiredReach(
        string $className,
        string $reachPath,
        TrustTier $tier,
    ): self {
        return new self(sprintf(
            'Cannot autowire "%s" for a %s tier extension: doing so would inject a service '
            . 'that hands out other services (%s). Bind a factory closure instead — a closure '
            . 'registered through the scoped container receives that scoped container, not the '
            . 'real one.',
            $className,
            ucfirst($tier->value),
            $reachPath,
        ));
    }

    /**
     * Denied because autowiring the named class would inject a RESTRICTED service.
     *
     * The constructor of an extension-supplied class is chosen by the extension
     * but filled by the container, so a service provider could name `MasterKey`
     * as a dependency and receive it without holding `CryptoKeyAccess`. The
     * restriction map applies to a constructor parameter exactly as it applies
     * to a `get()`.
     */
    #[NoDiscard]
    public static function forInjectedService(
        string $className,
        string $serviceId,
        TrustTier $tier,
        ExtensionCapability $required,
    ): self {
        return new self(sprintf(
            'Cannot autowire "%s" for a %s tier extension: it asks for "%s", which requires '
            . 'the %s capability. Grant it in config/extensions.php by adding '
            . "'additional_capabilities' => ['%s'], or drop the dependency.",
            $className,
            ucfirst($tier->value),
            $serviceId,
            $required->name,
            $required->name,
        ));
    }

    /**
     * Denied a route at a path the framework reserves.
     *
     * Deliberately not phrased as a missing capability, for the same reason as
     * {@see self::forSandboxEscape()}: there is no tier that unlocks `/login`.
     * ADR-0023 promised this control from the day it was written and no
     * reserved-path list existed anywhere in the framework — Community and
     * Untrusted were kept out by the `/ext/` prefix, which made the sentence
     * accidentally true for them and simply false for Verified.
     */
    #[NoDiscard]
    public static function forReservedPath(
        string $path,
        TrustTier $tier,
    ): self {
        return new self(sprintf(
            'Cannot register a route at "%s" from a %s tier extension: it is a path the framework '
            . 'reserves for the application (login, logout, admin, _studio, api), and shadowing one '
            . 'would let an extension serve the page a visitor types or a redirect targets. '
            . 'No capability grant unlocks this. Register under a path of your own — "%s/..." is '
            . 'yours, and is not reserved.',
            $path,
            ucfirst($tier->value),
            rtrim($path, '/'),
        ));
    }

    /**
     * Denied a route name another route already holds.
     *
     * The companion to {@see self::forReservedPath()}, and the reason it is not
     * enough on its own. A route path collides first-registered-wins in
     * {@see \Pulsar\Routing\Router}; a route NAME collides last-wins, and the
     * name is what `route()` reads. Reserving `/login` stops an extension
     * serving the sign-in page and does not stop it becoming the destination of
     * every link the host generates to it.
     *
     * Phrased as a collision rather than a missing capability because that is
     * what it is: the name is taken, no tier unlocks a taken name, and the
     * remedy is a different name rather than a config change.
     */
    #[NoDiscard]
    public static function forTakenRouteName(
        string $name,
        string $heldBy,
        TrustTier $tier,
    ): self {
        return new self(sprintf(
            'Cannot register a route named "%s" from a %s tier extension: that name is already '
            . 'held by the route at "%s". Route names are how the application generates URLs, '
            . 'and the last registration of a name wins — so taking one re-points every link, '
            . 'redirect and mail body that asks for it. No capability grant unlocks this. '
            . 'Choose a name of your own.',
            $name,
            ucfirst($tier->value),
            $heldBy,
        ));
    }

    /**
     * Denied access to an unknown (unclassified) service.
     */
    #[NoDiscard]
    public static function forUnknownService(
        string $serviceId,
        TrustTier $tier,
    ): self {
        return new self(sprintf(
            'Service "%s" is not classified in the service restriction map: '
            . 'denied by default for %s tier. '
            . 'Add the service to the safe allowlist or restriction map in ServiceRestrictionMap.',
            $serviceId,
            ucfirst($tier->value),
        ));
    }
}
