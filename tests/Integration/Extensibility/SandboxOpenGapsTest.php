<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extensibility;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Internal\ScopedContainerProxy;
use Pulsar\Extensibility\Internal\ScopedRouterProxy;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Routing\Router;
use ReflectionProperty;

/**
 * The escapes that are still open, executed rather than footnoted.
 *
 * {@see SandboxEscapeRoutesTest} holds the routes that are CLOSED, one test
 * each. This file is its other half: the ones that are not, each run to the
 * point where it succeeds, so the limitation is a demonstrated fact with a
 * reproduction attached instead of a sentence in an ADR that nobody re-checks.
 *
 * Every test here ASSERTS THE GAP IS STILL OPEN and then marks itself
 * incomplete. Two consequences, both wanted:
 *
 *  - The suite reports them on every run. A known hole stays visible; it does
 *    not decay into silence the way a comment does.
 *  - Closing one of these BREAKS ITS TEST. That is the point. Whoever closes it
 *    is forced to come here, delete the test, and correct
 *    `docs/adr/0023-extension-trust-tiers.md`, so the ADR and the code cannot
 *    drift apart in the direction that flatters the sandbox.
 *
 * They are marked incomplete rather than failed so that a known, documented,
 * accepted limitation does not hold the pipeline red — CI would learn to ignore
 * a permanently failing job, which is how a real regression gets through. The
 * `open-gap` group makes the set addressable: `--group open-gap` lists exactly
 * what the sandbox does not stop.
 */
#[Group('open-gap')]
#[CoversNothing]
final class SandboxOpenGapsTest extends TestCase
{
    private function container(): Container
    {
        $container = new Container();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(LoggerInterface::class, new NullLogger());

        return $container;
    }

    private function proxy(Container $container, TrustTier $tier = TrustTier::Untrusted): ScopedContainerProxy
    {
        return new ScopedContainerProxy(
            $container,
            $tier,
            CapabilityPolicy::defaults(),
            ServiceRestrictionMap::defaults(),
            'acme/evil',
        );
    }

    // --- Gap 1: reflection ---------------------------------------------------

    /**
     * Reflection reads the private `$inner` property and returns the real
     * container, at any tier, in one line.
     *
     * This is the one gap on this page that is not a decision anyone can
     * revisit. An extension is in-process PHP; `ReflectionProperty` reads
     * private state and PHP offers no way to withhold that from code sharing
     * the interpreter. Closing it means the extension stops sharing the
     * interpreter — process isolation, weighed and rejected in ADR-0023 for
     * cost reasons that have not changed.
     *
     * What the tier system is therefore worth: it makes reach explicit and
     * auditable, and it stops the ACCIDENTAL and the casual. It is not a memory
     * boundary, and a hostile extension remains hostile code that someone chose
     * to install.
     */
    #[Test]
    public function reflectionStillReachesTheUnscopedContainer(): void
    {
        $container = $this->container();
        $proxy = $this->proxy($container);

        $inner = new ReflectionProperty(ScopedContainerProxy::class, 'inner')->getValue($proxy);

        self::assertSame(
            $container,
            $inner,
            'if this no longer returns the real container, the gap is closed and ADR-0023 must say so',
        );

        self::markTestIncomplete(
            'OPEN GAP — reflection reaches the unscoped container from any tier. '
            . 'Not closable in-process: PHP exposes private state to any code in the '
            . 'same interpreter. Closing it requires process isolation, which ADR-0023 '
            . 'considered and rejected. Documented under "What this does not stop".',
        );
    }

    // --- Gap 2: the route table is readable ----------------------------------

    /**
     * `ScopedRouterProxy::routes()` delegates unfiltered, so an Untrusted
     * extension can enumerate every path the host registered, `/admin` and
     * `/login` included.
     *
     * Registration is still confined to the extension's prefix, which
     * `ScopedRouterProxyTest` pins separately, so this grants no route. What it
     * reveals is the host's whole URL surface — path, name and HANDLER, the
     * last of which may be a closure the host defined and the extension can
     * then invoke with arguments of its choosing. Closing it means deciding
     * what a filtered route table should return, and every legitimate consumer
     * of `routes()` — link generation, diagnostics, an extension's own
     * introspection — expects the whole table.
     */
    #[Test]
    public function anyTierCanStillEnumerateTheHostsRouteTable(): void
    {
        $router = new Router();
        $router->get('/admin', static fn(): string => 'host only');
        $router->get('/login', static fn(): string => 'host only');

        $proxy = new ScopedRouterProxy($router, TrustTier::Untrusted, 'acme/evil', CapabilityPolicy::defaults());

        self::assertEquals(
            $router->routes(),
            $proxy->routes(),
            'the proxy discloses the host route table verbatim; if it now filters, close the gap in ADR-0023',
        );

        self::markTestIncomplete(
            'OPEN GAP — an Untrusted extension can read every host route, including '
            . '/admin and /login. Disclosure only: registration remains prefix-confined. '
            . 'Closing it requires deciding what a filtered route table returns for '
            . 'link generation and diagnostics, which expect the whole table.',
        );
    }

    // --- Gap 3: forged events ------------------------------------------------

    /**
     * `Psr\EventDispatcher\EventDispatcherInterface` is safe-listed, so any tier
     * with `ContainerRead` resolves the host's dispatcher and can fire anything
     * the host listens for.
     *
     * Executed end to end here rather than argued: a host listener is
     * registered, an Untrusted extension resolves the dispatcher through the
     * scoped proxy, dispatches an event it forged, and the host's listener
     * runs.
     *
     * Adding a LISTENER is a separate power and costs `ServiceRegister`, which
     * this tier does not hold: `ListenerProviderInterface` used to be
     * unclassified — denied by accident rather than by decision, and denied to
     * `pulsar/forum`, which registers its notification listeners in
     * `postBoot()`, as readily as to an attacker. It is priced now, so
     * Untrusted still cannot add one and Community and above can. This gap is
     * therefore write-only into the host's event flow for the tier under test,
     * which is quite enough to matter if the host acts on domain or auth
     * events.
     *
     * Left open deliberately: the dispatcher is how extensions integrate at
     * all, and removing it from the safe list would break substantially every
     * extension. The real fix is a per-event-type authorization the framework
     * does not currently have, which is a larger change than this one.
     */
    #[Test]
    public function anyTierCanStillDispatchForgedHostEvents(): void
    {
        $container = $this->container();
        $dispatcher = new RecordingHostDispatcher();
        $container->instance(EventDispatcherInterface::class, $dispatcher);

        $resolved = $this->proxy($container)->get(EventDispatcherInterface::class);

        self::assertSame($dispatcher, $resolved, 'the extension holds the host dispatcher itself');

        $resolved->dispatch(new ForgedHostEvent());

        self::assertSame(
            [ForgedHostEvent::class],
            $dispatcher->handledByHost,
            'the host listener acted on an event an Untrusted extension forged',
        );

        self::markTestIncomplete(
            'OPEN GAP — any tier with ContainerRead can dispatch any event the host '
            . 'listens for, including forged auth and domain events. Adding a listener '
            . 'costs ServiceRegister, which this tier does not hold. Closing the '
            . 'dispatch half needs per-event-type authorization, which the framework '
            . 'does not have; removing the dispatcher from the safe list would break '
            . 'nearly every extension.',
        );
    }
}

/**
 * A host dispatcher with a host listener behind it, so a forged dispatch can be
 * observed doing what a real one would.
 */
final class RecordingHostDispatcher implements EventDispatcherInterface
{
    /** @var list<string> */
    public array $handledByHost = [];

    public function dispatch(object $event): object
    {
        // Stands in for whatever the host listens for — an auth or domain
        // handler that acts on the event it is given.
        $this->handledByHost[] = $event::class;

        return $event;
    }
}

/** An event the extension had no business raising. */
final class ForgedHostEvent {}
