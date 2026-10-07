<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extensibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Config\TrustedExtensionsConfig;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Provider\DeferredServiceProviderInterface;
use Pulsar\Core\Boot\ExtensionSandbox;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Exception\CapabilityDeniedException;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\ExtensionCatalogInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Extensibility\Internal\ScopedContainerProxy;
use Pulsar\Extensibility\Internal\ScopedDeferredProvider;
use Pulsar\Extensibility\Internal\ScopedRouterProxy;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extensibility\ShutdownAwareExtensionInterface;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;
use Pulsar\Security\Crypto\MasterKey;
use stdClass;
use Throwable;

use function class_exists;
use function dirname;
use function sprintf;
use function str_repeat;

use const DIRECTORY_SEPARATOR;

/**
 * The escape routes, one test each, executed rather than argued.
 *
 * Every case here ran successfully against the sandbox at some point: from
 * inside an extension the host capped at Community, each returned the real
 * container, the real router, or something that hands them out. They share one
 * shape — the proxy gated the service ID an extension ASKED for, while five
 * other paths delivered an object without an ID being involved at all — and
 * they are gathered in one file so that shape stays visible.
 *
 * A test here that starts passing for the wrong reason (the extension failing
 * to boot for an unrelated cause) would be worse than no test, so each asserts
 * on what the extension ACTUALLY received, not merely that something threw.
 */
#[CoversClass(ScopedContainerProxy::class)]
#[CoversClass(ScopedDeferredProvider::class)]
#[CoversClass(ExtensionBootstrap::class)]
final class SandboxEscapeRoutesTest extends TestCase
{
    /**
     * A class that exists on disk but is not autoloadable, so it does not
     * exist until an extension says it does.
     */
    private const string LATE_DECLARED_PROBE = 'Acme\\Evil\\DeclaredAfterBinding';

    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config';
        EscapeProbe::reset();
    }

    // --- Route 1: resolve the container by name -----------------------------

    #[Test]
    public function theContainerCannotBeResolvedByName(): void
    {
        $this->expectBootDenial(static function (ContainerInterface $scoped): void {
            EscapeProbe::$captured = $scoped->get(ContainerInterface::class);
        });
    }

    #[Test]
    public function theRouterCannotBeResolvedByName(): void
    {
        $this->expectBootDenial(static function (ContainerInterface $scoped): void {
            EscapeProbe::$captured = $scoped->get(RouterInterface::class);
        });
    }

    // --- Route 2: call() autowires from the real graph ----------------------

    /**
     * `call()` asserted nothing whatsoever, and the container it delegated to
     * resolves every type-hinted parameter through its own `get()`. One line
     * returned the container the whole sandbox is built around.
     */
    #[Test]
    public function callCannotAutowireTheContainerIntoACallable(): void
    {
        $this->expectBootDenial(static function (ContainerInterface $scoped): void {
            EscapeProbe::$captured = $scoped->call(
                static fn(ContainerInterface $real): ContainerInterface => $real,
            );
        });
    }

    /**
     * The same hole reached restricted services, not only the container: a
     * parameter type is a service ID by another name.
     */
    #[Test]
    public function callCannotAutowireARestrictedServiceIntoACallable(): void
    {
        $this->expectBootDenial(static function (ContainerInterface $scoped): void {
            EscapeProbe::$captured = $scoped->call(
                static fn(MasterKey $key): MasterKey => $key,
            );
        });
    }

    #[Test]
    public function callStillResolvesWhatTheTierMayHave(): void
    {
        $this->boot(static function (ContainerInterface $scoped): void {
            EscapeProbe::$captured = $scoped->call(
                static fn(LoggerInterface $logger): LoggerInterface => $logger,
            );
        });

        self::assertInstanceOf(NullLogger::class, EscapeProbe::$captured);
    }

    // --- Route 3: the container invokes a factory closure with itself -------

    #[Test]
    public function aFactoryClosureIsInvokedWithTheScopedContainer(): void
    {
        $this->boot(static function (ContainerInterface $scoped): void {
            // A NEW id (ServiceRegister, which Community holds) that is also
            // on the safe list, so the extension can resolve it back and make
            // the factory actually run.
            $scoped->bind(
                'Psr\Clock\ClockInterface',
                static function (ContainerInterface $handed): object {
                    EscapeProbe::$captured = $handed;

                    return new stdClass();
                },
            );

            $_ = $scoped->get('Psr\Clock\ClockInterface');
        });

        self::assertInstanceOf(
            ScopedContainerProxy::class,
            EscapeProbe::$captured,
            'the factory the extension registered must be handed its own scoped container',
        );
    }

    // --- Route 4: binding a class NAME lets the container pick the arguments -

    /**
     * The class is no longer VETTED at bind time; it is BUILT at resolve time,
     * through the scope, and that is the whole point.
     *
     * The vetting it replaces walked the constructor four levels deep and gave
     * up past that, and returned early for a class that did not exist yet — a
     * state the extension controls, since it decides when its own files load.
     * Both were escapes. `construct()` cannot be walked around by adding a hop
     * (there is no depth) or by declaring the class later (there is nothing to
     * build until it exists), so the denial simply arrives at the moment the
     * class is actually needed.
     */
    #[Test]
    public function aBoundClassCannotBeAutowiredWithTheContainer(): void
    {
        $this->expectBootDenial(static function (ContainerInterface $scoped): void {
            $scoped->bind('acme.service', EscapeProbeWantsContainer::class);
            EscapeProbe::$captured = $scoped->get('acme.service');
        });
    }

    #[Test]
    public function aBoundClassCannotBeAutowiredWithARestrictedService(): void
    {
        $this->expectBootDenial(static function (ContainerInterface $scoped): void {
            $scoped->bind('acme.service', EscapeProbeWantsMasterKey::class);
            EscapeProbe::$captured = $scoped->get('acme.service');
        });
    }

    /**
     * One indirection was all it took while the vetting had a depth bound: the
     * container fills the whole constructor graph and the walk stopped at four.
     * There is no bound to exceed now — the collaborator is built by the scope
     * too, asks the scope for the master key, and is refused there.
     */
    #[Test]
    public function aBoundClassCannotReachARestrictedServiceThroughACollaborator(): void
    {
        $this->expectBootDenial(static function (ContainerInterface $scoped): void {
            $scoped->bind('acme.service', EscapeProbeWantsItIndirectly::class);
            EscapeProbe::$captured = $scoped->get('acme.service');
        });
    }

    /**
     * A class declared AFTER it is bound is built by the scope like any other.
     *
     * `assertAutowirable()` opened with `if (!class_exists($className)) return;`
     * — it could not reflect a class that was not loaded, so it waved the
     * binding through. An extension chooses when its own classes are declared,
     * so bind-then-declare-then-resolve skipped the check entirely and the
     * container autowired itself into the constructor. Nothing is checked at
     * bind time now, so there is nothing to be early for.
     */
    #[Test]
    public function aClassBoundBeforeItExistsIsStillBuiltThroughTheScope(): void
    {
        $className = self::LATE_DECLARED_PROBE;
        $fixture = __DIR__ . DIRECTORY_SEPARATOR . 'Fixture' . DIRECTORY_SEPARATOR . 'late_declared_probe.php';

        self::assertFalse(
            class_exists($className, false),
            'the fixture must not be loaded before the binding, or this proves nothing',
        );

        $this->expectBootDenial(static function (ContainerInterface $scoped) use ($className, $fixture): void {
            // Bound while the class does not exist: the old check returned here.
            $scoped->bind('acme.late', $className);

            // The extension then declares it, exactly as its autoloader would.
            require_once $fixture;

            EscapeProbe::$captured = $scoped->get('acme.late');
        });
    }

    #[Test]
    public function anOrdinaryClassCanStillBeBoundByName(): void
    {
        $this->boot(static function (ContainerInterface $scoped): void {
            $scoped->bind('acme.service', EscapeProbeHarmless::class);
            EscapeProbe::$captured = $scoped->get('acme.service');
        });

        self::assertInstanceOf(EscapeProbeHarmless::class, EscapeProbe::$captured);
    }

    // --- Route 5: the container invokes a decorator with itself -------------

    /**
     * The decorator closure receives the SCOPED container as its second
     * argument, and the decorated SERVICE as its first — which is a resolution,
     * and used to be the one nobody gated.
     *
     * `decorate()` asked whether the tier held `ServiceDecorate` and never
     * asked about the id, so the answer to "may this extension hold this
     * service" depended on which method it asked through. The id is now
     * asserted exactly as `get()` asserts it, which is why the extension binds
     * its own target here: an unclassified id the HOST bound is denied by
     * default, through `decorate()` as through `get()`.
     */
    #[Test]
    public function aDecoratorClosureIsInvokedWithTheScopedContainer(): void
    {
        // ServiceDecorate is a Verified capability; pulsar/analytics ships
        // listed at that tier, so this is the most privileged non-Core case.
        $container = $this->container();

        $this->boot(
            static function (ContainerInterface $scoped): void {
                $scoped->bind('acme.target', static fn(): object => new stdClass());
                $scoped->decorate(
                    'acme.target',
                    static function (object $inner, ContainerInterface $handed): object {
                        EscapeProbe::$captured = $handed;

                        return $inner;
                    },
                );
            },
            name: 'pulsar/analytics',
            container: $container,
        );

        $_ = $container->get('acme.target');

        self::assertInstanceOf(ScopedContainerProxy::class, EscapeProbe::$captured);
    }

    /**
     * Decorating cannot deliver a service the tier could not resolve.
     *
     * `decorate('...\MasterKey', fn($key, $c) => $key)` handed the key to
     * extension code the next time the id resolved, without `CryptoKeyAccess`
     * being consulted, because the guard on this method was about decorating
     * and not about the service being decorated.
     */
    #[Test]
    public function decoratingCannotDeliverARestrictedService(): void
    {
        // The extension has to be one the host has NOT granted CryptoKeyAccess,
        // which is a fact about config/extensions.php rather than about the
        // sandbox — and it changed under this test once already, when
        // `pulsar/analytics` was granted the capability for its own pseudonym
        // pepper and the test started reporting an escape that was a grant. The
        // premise is asserted rather than assumed, so the next such grant fails
        // here with a sentence saying what happened.
        $extension = 'pulsar/tickets';
        $granted = TrustedExtensionsConfig::fromArray(
            self::trustedExtensions(),
        )->additionalCapabilities($extension);

        self::assertNotContains(
            ExtensionCapability::CryptoKeyAccess,
            $granted,
            sprintf(
                'config/extensions.php now grants "%s" CryptoKeyAccess, so it is no longer a '
                . 'tier that must be refused the master key. Point this test at a Verified '
                . 'extension the host has not granted it.',
                $extension,
            ),
        );

        $container = $this->container();
        $container->bind(MasterKey::class, static fn(): MasterKey => MasterKey::fromHex(str_repeat('41', 32)));

        try {
            $this->boot(
                static function (ContainerInterface $scoped): void {
                    $scoped->decorate(
                        MasterKey::class,
                        static function (object $inner): object {
                            EscapeProbe::$captured = $inner;

                            return $inner;
                        },
                    );
                },
                name: $extension,
                container: $container,
            );
            self::fail('a Verified extension decorated the master key without CryptoKeyAccess');
        } catch (ExtensionException) {
            self::assertNull(EscapeProbe::$captured);
        }
    }

    /**
     * The host's allow-list, as the sandbox reads it.
     *
     * @return array<array-key, mixed>
     */
    private static function trustedExtensions(): array
    {
        /** @var array{trusted_extensions?: array<array-key, mixed>} $config */
        $config = require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . 'extensions.php';

        return $config['trusted_extensions'] ?? [];
    }

    // --- Route 6: the extension registry is writable -------------------------

    /**
     * The registry was safe-listed, and it is not a read-only view: `add()`
     * let a Community extension register an accomplice under a name the HOST
     * trusts at Core, which then received the unwrapped container in postBoot;
     * `setState()` let it mark a security extension Failed so it never booted.
     */
    #[Test]
    public function theExtensionRegistryCannotBeResolved(): void
    {
        $this->expectBootDenial(static function (ContainerInterface $scoped): void {
            EscapeProbe::$captured = $scoped->get(ExtensionRegistry::class);
        });
    }

    // --- Route 10: the composition root can bind anything to any id ---------

    /**
     * The safe-list says a TYPE is inert; it cannot say what the application
     * bound to that type's ID. This is the original defect in miniature —
     * `ContainerInterface` was classified safe and the Kernel bound it to the
     * real container — so the guard has to judge the object, not the name.
     */
    #[Test]
    public function aSafeListedIdBoundToAContainerYieldsTheScopedProxyInstead(): void
    {
        $container = $this->container();
        $container->instance('Psr\Clock\ClockInterface', $this->createStub(PsrContainerInterface::class));

        $this->boot(static function (ContainerInterface $scoped): void {
            EscapeProbe::$captured = $scoped->get('Psr\Clock\ClockInterface');
        }, container: $container);

        self::assertInstanceOf(ScopedContainerProxy::class, EscapeProbe::$captured);
    }

    #[Test]
    public function aSafeListedIdBoundToARouterYieldsTheScopedRouterInstead(): void
    {
        $container = $this->container();
        $container->instance('Psr\Clock\ClockInterface', $this->createStub(RouterInterface::class));

        $this->boot(static function (ContainerInterface $scoped): void {
            EscapeProbe::$captured = $scoped->get('Psr\Clock\ClockInterface');
        }, container: $container);

        self::assertInstanceOf(ScopedRouterProxy::class, EscapeProbe::$captured);
    }

    #[Test]
    public function aSafeListedIdBoundToAServiceDispenserIsRefused(): void
    {
        $container = $this->container();
        $container->instance('Psr\Clock\ClockInterface', $this->createStub(ExtensionCatalogInterface::class));

        try {
            $this->boot(static function (ContainerInterface $scoped): void {
                EscapeProbe::$captured = $scoped->get('Psr\Clock\ClockInterface');
            }, container: $container);
            self::fail('an extension catalog reached the extension through a safe-listed id');
        } catch (ExtensionException) {
            self::assertNull(EscapeProbe::$captured);
        }
    }

    // --- Route 7: the bootstrap owns the policy and is publicly settable -----

    #[Test]
    public function theExtensionBootstrapCannotBeReached(): void
    {
        $this->expectBootDenial(static function (ContainerInterface $scoped): void {
            EscapeProbe::$captured = $scoped->call(
                static fn(ExtensionBootstrap $bootstrap): ExtensionBootstrap => $bootstrap,
            );
        });
    }

    // --- Route 8: a service provider's constructor is filled by the container -

    #[Test]
    public function aProviderCannotBeAutowiredWithTheContainer(): void
    {
        $bootstrap = $this->bootstrap();
        $container = $this->container();

        $bootstrap->addExtension(
            new EscapeProbeExtension('acme/evil', static function (): void {}, [EscapeProbeProvider::class]),
            self::manifest('acme/evil'),
        );

        try {
            $bootstrap->register($container);
            self::fail('the provider was constructed with the unscoped container');
        } catch (ExtensionException) {
            self::assertNull(
                EscapeProbe::$captured,
                'the provider must not have been constructed at all',
            );
        }
    }

    // --- Route 9: a deferred provider runs long after its scope --------------

    /**
     * Deferring moved `register()` to the first resolution of a provided id,
     * where the registry calls it with the container doing the resolving — the
     * real one. The scope was established at boot and simply absent at the
     * moment it mattered.
     */
    #[Test]
    public function aDeferredProviderRegistersThroughItsScopedContainer(): void
    {
        $container = $this->container();

        $this->boot(
            static function (): void {},
            container: $container,
            providers: [EscapeProbeDeferredProvider::class],
        );

        $_ = $container->get('acme.deferred');

        self::assertInstanceOf(
            ScopedContainerProxy::class,
            EscapeProbe::$captured,
            'a deferred register() must see what an eager register() would have seen',
        );
    }

    /**
     * Every extension's deferred provider arrives wrapped in the same class, so
     * a registry that de-duplicated by class name would run the first and
     * silently skip every other extension's.
     */
    #[Test]
    public function twoExtensionsDeferredProvidersBothRegister(): void
    {
        $bootstrap = $this->bootstrap();
        $container = $this->container();

        $bootstrap->addExtension(
            new EscapeProbeExtension('acme/one', static function (): void {}, [EscapeProbeDeferredProvider::class]),
            self::manifest('acme/one'),
        );
        $bootstrap->addExtension(
            new EscapeProbeExtension('acme/two', static function (): void {}, [EscapeProbeOtherDeferredProvider::class]),
            self::manifest('acme/two'),
        );

        $bootstrap->register($container);

        $_ = $container->get('acme.deferred');
        $_ = $container->get('acme.deferred.other');

        self::assertSame(
            ['acme.deferred', 'acme.deferred.other'],
            EscapeProbe::$registered,
        );
    }

    // --- Route 11: shutdown() was a fifth lifecycle hook nobody counted ------

    /**
     * `Kernel::shutdown()` called `$extension->shutdown($this->container)` with
     * the REAL container.
     *
     * Four phases scoped and the fifth did not, because the fifth lived in the
     * Kernel rather than beside the other four. An extension that could not
     * obtain the container in `register()`, `preBoot()`, `boot()` or
     * `postBoot()` was handed it on the way out, at every tier.
     */
    #[Test]
    public function shutdownRunsThroughTheExtensionScope(): void
    {
        $bootstrap = $this->bootstrap();
        $container = $this->container();

        $bootstrap->addExtension(
            new EscapeProbeShutdownExtension('acme/evil'),
            self::manifest('acme/evil'),
        );
        $bootstrap->register($container);
        $bootstrap->shutdown($container);

        self::assertInstanceOf(
            ScopedContainerProxy::class,
            EscapeProbe::$captured,
            'the shutdown hook must see what the other four phases see',
        );
    }

    // --- Route 12: a route handler is a class the framework builds ----------

    /**
     * A route handler is an unvetted class name that the framework constructs
     * at request time, from the container it has to hand — the real one.
     *
     * Nothing about `[AcmeController::class, 'index']` says which extension
     * registered it, so by the time `ReflectionControllerResolver` asks the
     * container for it, every capability check has long finished running and a
     * constructor asking for `ContainerInterface` was simply filled.
     */
    #[Test]
    public function aRouteHandlerIsConstructedThroughTheScope(): void
    {
        $container = $this->container();

        $this->boot(
            static function (ContainerInterface $scoped, RouterInterface $router): void {
                $router->get('/probe', [EscapeProbeController::class, 'index'], 'probe');
            },
            container: $container,
        );

        // What the framework does at request time: ask the container for the
        // handler class by name.
        try {
            EscapeProbe::$captured = $container->get(EscapeProbeController::class);
            self::fail('the route handler was built with the real container');
        } catch (Throwable $thrown) {
            // Caught as Throwable because the denial travels out of a factory
            // the container invokes, and the container's own contract does not
            // declare it — asserting the type here is what keeps the test from
            // passing on an unrelated failure.
            self::assertInstanceOf(CapabilityDeniedException::class, $thrown);
            self::assertNull(EscapeProbe::$captured);
        }
    }

    #[Test]
    public function anOrdinaryRouteHandlerStillResolves(): void
    {
        $container = $this->container();

        $this->boot(
            static function (ContainerInterface $scoped, RouterInterface $router): void {
                $router->get('/probe', [EscapeProbeHarmlessController::class, 'index'], 'probe');
            },
            container: $container,
        );

        self::assertInstanceOf(
            EscapeProbeHarmlessController::class,
            $container->get(EscapeProbeHarmlessController::class),
        );
    }

    // --- Route 13: three capabilities that gated nothing --------------------

    /**
     * `AuditWrite` was declared, granted down to Community, and consulted
     * nowhere, while `AuditLoggerInterface` sat on the SAFE list — so Untrusted,
     * which is granted `ContainerRead` and explicitly not `AuditWrite`, resolved
     * the host's audit logger and wrote entries with it.
     */
    #[Test]
    public function auditWriteNowGatesTheAuditLogger(): void
    {
        $container = $this->container();
        $container->instance('Pulsar\Audit\AuditLoggerInterface', new stdClass());

        self::assertInstanceOf(
            stdClass::class,
            $this->scoped($container, TrustTier::Community)->get('Pulsar\Audit\AuditLoggerInterface'),
            'Community holds AuditWrite and must keep the logger',
        );

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('AuditWrite');
        $_ = $this->scoped($container, TrustTier::Untrusted)->get('Pulsar\Audit\AuditLoggerInterface');
    }

    /**
     * `MiddlewareRegister` was granted to Verified by the policy and refused to
     * Verified by `SandboxReach`, so the tier table described a control that
     * existed in neither direction.
     */
    #[Test]
    public function middlewareRegisterNowGatesTheGlobalPipeline(): void
    {
        $container = $this->container();
        $pipeline = $this->createStub('Pulsar\Http\Middleware\MiddlewarePipelineInterface');
        $container->instance('Pulsar\Http\Middleware\MiddlewarePipelineInterface', $pipeline);

        self::assertSame(
            $pipeline,
            $this->scoped($container, TrustTier::Verified)
                ->get('Pulsar\Http\Middleware\MiddlewarePipelineInterface'),
            'Verified is granted MiddlewareRegister and must be able to use it',
        );

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('MiddlewareRegister');
        $_ = $this->scoped($container, TrustTier::Community)
            ->get('Pulsar\Http\Middleware\MiddlewarePipelineInterface');
    }

    /**
     * The framework's own boot artifact store was safe-listed, so any tier
     * holding `ContainerRead` — Untrusted included — could rewrite the compiled
     * routes, resolution hints and views that the NEXT boot executes.
     */
    #[Test]
    public function theFrameworkCacheCannotBeReachedAtAnyTier(): void
    {
        $container = $this->container();
        $container->instance('Pulsar\Cache\FrameworkCacheInterface', new stdClass());

        foreach ([TrustTier::Verified, TrustTier::Community, TrustTier::Untrusted] as $tier) {
            try {
                $_ = $this->scoped($container, $tier)->get('Pulsar\Cache\FrameworkCacheInterface');
                self::fail($tier->value . ' reached the framework boot cache');
            } catch (CapabilityDeniedException $denial) {
                self::assertStringContainsString('not classified', $denial->getMessage());
            }
        }
    }

    /**
     * `CommandRegister` was declared, granted down to Community, and consulted
     * nowhere: `bin/pulsar` resolved every extension-declared command class
     * straight off the real container.
     */
    #[Test]
    public function aCommandIsBuiltThroughTheDeclaringExtensionsScope(): void
    {
        $bootstrap = $this->bootstrap();
        $container = $this->container();

        $bootstrap->addExtension(
            new EscapeProbeExtension('acme/evil', static function (): void {}),
            self::manifestProvidingCommand('acme/evil', EscapeProbeCommand::class),
        );
        $bootstrap->register($container);

        $this->expectException(CapabilityDeniedException::class);
        $_ = $bootstrap->buildCommand(EscapeProbeCommand::class, $container);
    }

    #[Test]
    public function aCommandFromATierWithoutCommandRegisterIsRefused(): void
    {
        $bootstrap = $this->bootstrap();
        $bootstrap->trustedExtensionsConfig = TrustedExtensionsConfig::fromArray([
            'acme/evil' => ['tier' => 'untrusted'],
        ]);
        $container = $this->container();

        $bootstrap->addExtension(
            new EscapeProbeExtension('acme/evil', static function (): void {}),
            self::manifestProvidingCommand('acme/evil', EscapeProbeHarmlessCommand::class),
        );
        $bootstrap->register($container);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('CommandRegister');
        $_ = $bootstrap->buildCommand(EscapeProbeHarmlessCommand::class, $container);
    }

    // --- The other half: legitimate work still works ------------------------

    /**
     * An extension resolving the service it registered itself.
     *
     * This is the shape of every bundled extension's provider —
     * `bind(TicketRepositoryInterface::class, ...)` in `register()`, then
     * `get(TicketRepositoryInterface::class)` from a factory later — and the
     * previous round of sandbox work denied it: an id the extension had just
     * bound was still "not classified in the service restriction map", so every
     * bundled extension above Core stopped at the first resolution of its own
     * binding.
     */
    #[Test]
    public function anExtensionCanResolveTheServiceItRegistered(): void
    {
        $this->boot(static function (ContainerInterface $scoped): void {
            $scoped->bind('acme.own.service', static fn(): object => new stdClass());
            EscapeProbe::$captured = $scoped->get('acme.own.service');
        });

        self::assertInstanceOf(stdClass::class, EscapeProbe::$captured);
    }

    /**
     * And across phases, which is why the scope is built once and kept.
     *
     * Each lifecycle phase used to ask for a scope of its own, so a binding
     * recorded by the `register()` scope was invisible to the `boot()` one.
     */
    #[Test]
    public function aServiceRegisteredInRegisterIsResolvableInBoot(): void
    {
        $bootstrap = $this->bootstrap();
        $container = $this->container();
        $router = new Router();

        $bootstrap->addExtension(
            new EscapeProbePhaseExtension('acme/evil'),
            self::manifest('acme/evil'),
        );
        $bootstrap->register($container);
        $bootstrap->boot($container, $router);

        self::assertInstanceOf(stdClass::class, EscapeProbe::$captured);
    }

    /**
     * An extension autowiring one of its OWN classes that nothing bound.
     *
     * Deny-by-default is a rule about the host's service graph, and the
     * extension's own class was never part of it — `analytics` has a
     * `Dsar\AnalyticsDsarCollector` that no provider registers. It is built by
     * the scope rather than delegated, so its own dependencies are still gated.
     */
    #[Test]
    public function anExtensionCanAutowireItsOwnUnboundClass(): void
    {
        $this->boot(static function (ContainerInterface $scoped): void {
            EscapeProbe::$captured = $scoped->get(EscapeProbeHarmless::class);
        });

        self::assertInstanceOf(EscapeProbeHarmless::class, EscapeProbe::$captured);
    }

    /**
     * ...and that permission is not permission for the CONTAINER to decide what
     * goes inside it.
     *
     * `Container::get()` autowires an unbound instantiable concrete from the
     * real graph, so delegating an approved own-code id would fill its
     * constructor with services the scope never saw.
     */
    #[Test]
    public function anOwnClassIsStillBuiltThroughTheScope(): void
    {
        $this->expectBootDenial(static function (ContainerInterface $scoped): void {
            EscapeProbe::$captured = $scoped->get(EscapeProbeWantsItIndirectly::class);
        });
    }

    // --- Helpers ------------------------------------------------------------

    private function bootstrap(): ExtensionBootstrap
    {
        $bootstrap = ExtensionBootstrap::create();
        ExtensionSandbox::harden($bootstrap, $this->configDir);

        return $bootstrap;
    }

    private function container(): Container
    {
        $container = new Container();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(LoggerInterface::class, new NullLogger());
        $container->instance(MasterKey::class, MasterKey::fromHex(str_repeat('41', 32)));

        return $container;
    }

    /**
     * @param callable(ContainerInterface, RouterInterface): void $body
     * @param list<class-string<ServiceProviderInterface>> $providers
     */
    private function boot(
        callable $body,
        string $name = 'acme/evil',
        ?Container $container = null,
        array $providers = [],
    ): void {
        $bootstrap = $this->bootstrap();
        $container ??= $this->container();
        $router = new Router();

        $bootstrap->addExtension(
            new EscapeProbeExtension($name, $body, $providers),
            self::manifest($name),
        );
        $bootstrap->register($container);
        $bootstrap->boot($container, $router);
    }

    /**
     * @param callable(ContainerInterface, RouterInterface): void $body
     */
    private function expectBootDenial(callable $body): void
    {
        try {
            $this->boot($body);
            self::fail('the sandbox handed the extension something it must not have');
        } catch (ExtensionException) {
            self::assertNull(
                EscapeProbe::$captured,
                'nothing may reach the extension, not even before the denial',
            );
        }
    }

    /**
     * A scope built straight, for the cases that are about the restriction map
     * rather than about a lifecycle phase.
     */
    private function scoped(Container $container, TrustTier $tier): ScopedContainerProxy
    {
        return new ScopedContainerProxy(
            $container,
            $tier,
            CapabilityPolicy::defaults(),
            ServiceRestrictionMap::defaults(),
            'acme/evil',
        );
    }

    /**
     * @param class-string $commandClass
     */
    private static function manifestProvidingCommand(string $name, string $commandClass): ExtensionManifest
    {
        return ExtensionManifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'extension_class' => 'EscapeProbeExtension',
            'trust_tier' => 'core',
            'provides' => ['commands' => [$commandClass]],
        ], __DIR__);
    }

    private static function manifest(string $name): ExtensionManifest
    {
        // trust_tier "core" is what the extension ASKS for; none of these names
        // is in config/extensions.php, so the host caps every one at Community.
        //
        // The base path is this directory, which is where the probe classes
        // below actually live — so they are code this fake extension SHIPS, and
        // the scope treats them as its own. A manifest with no path claims no
        // class at all, which is a separate case pinned in SandboxSurfaceTest.
        return ExtensionManifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'extension_class' => 'EscapeProbeExtension',
            'trust_tier' => 'core',
        ], __DIR__);
    }
}

/**
 * Shared landing pad for whatever an escape attempt manages to obtain.
 */
final class EscapeProbe
{
    public static mixed $captured = null;

    /** @var list<string> */
    public static array $registered = [];

    public static function reset(): void
    {
        self::$captured = null;
        self::$registered = [];
    }
}

final class EscapeProbeExtension implements ExtensionInterface, PostBootExtensionInterface
{
    /** @var callable(ContainerInterface, RouterInterface): void */
    private $body;

    /**
     * @param callable(ContainerInterface, RouterInterface): void $body
     * @param list<class-string<ServiceProviderInterface>> $providers
     */
    public function __construct(
        private readonly string $extensionName,
        callable $body,
        private readonly array $providers = [],
    ) {
        $this->body = $body;
    }

    public function name(): string
    {
        return $this->extensionName;
    }

    public function register(ContainerInterface $container): void {}

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        ($this->body)($container, $router);
    }

    public function postBoot(ContainerInterface $container): void {}

    /** @return list<class-string<ServiceProviderInterface>> */
    public function providers(): array
    {
        return $this->providers;
    }
}

final class EscapeProbeWantsContainer
{
    public function __construct(public ContainerInterface $container) {}
}

final class EscapeProbeWantsMasterKey
{
    public function __construct(public MasterKey $key) {}
}

final class EscapeProbeWantsItIndirectly
{
    public function __construct(public EscapeProbeWantsMasterKey $inner) {}
}

final class EscapeProbeHarmless
{
    public function __construct(public LoggerInterface $logger) {}
}

final class EscapeProbeProvider implements ServiceProviderInterface
{
    public function __construct(ContainerInterface $container)
    {
        EscapeProbe::$captured = $container;
    }

    public function register(ContainerInterface $container): void {}

    /** @return list<string> */
    public function provides(): array
    {
        return [];
    }
}

final class EscapeProbeDeferredProvider implements DeferredServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        EscapeProbe::$captured = $container;
        EscapeProbe::$registered[] = 'acme.deferred';
        $container->bind('acme.deferred', static fn(): object => new stdClass());
    }

    /** @return list<string> */
    public function provides(): array
    {
        return ['acme.deferred'];
    }

    public function isDeferred(): bool
    {
        return true;
    }
}

final class EscapeProbeOtherDeferredProvider implements DeferredServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        EscapeProbe::$registered[] = 'acme.deferred.other';
        $container->bind('acme.deferred.other', static fn(): object => new stdClass());
    }

    /** @return list<string> */
    public function provides(): array
    {
        return ['acme.deferred.other'];
    }

    public function isDeferred(): bool
    {
        return true;
    }
}

/**
 * A scope built straight, for the cases that are about the map rather than
 * about a lifecycle phase.
 */

final class EscapeProbeShutdownExtension implements ExtensionInterface, ShutdownAwareExtensionInterface
{
    public function __construct(private readonly string $extensionName) {}

    public function name(): string
    {
        return $this->extensionName;
    }

    public function register(ContainerInterface $container): void {}

    public function boot(ContainerInterface $container, RouterInterface $router): void {}

    /** @return list<class-string<ServiceProviderInterface>> */
    public function providers(): array
    {
        return [];
    }

    public function shutdown(ContainerInterface $container): void
    {
        EscapeProbe::$captured = $container;
    }
}

/** A route handler whose constructor asks for the container. */
final class EscapeProbeController
{
    public function __construct(public ContainerInterface $container) {}

    public function index(): string
    {
        return 'ok';
    }
}

/** A route handler that asks for nothing it may not have. */
final class EscapeProbeHarmlessController
{
    public function __construct(public LoggerInterface $logger) {}

    public function index(): string
    {
        return 'ok';
    }
}

/** A CLI command whose constructor asks for the container. */
final class EscapeProbeCommand
{
    public function __construct(public ContainerInterface $container) {}
}

/** A CLI command that asks for nothing. */
final class EscapeProbeHarmlessCommand
{
    public function __construct() {}
}

/**
 * Registers a service in one phase and resolves it in the next, which is what
 * a per-phase scope made impossible.
 */
final class EscapeProbePhaseExtension implements ExtensionInterface
{
    public function __construct(private readonly string $extensionName) {}

    public function name(): string
    {
        return $this->extensionName;
    }

    public function register(ContainerInterface $container): void
    {
        $container->bind('acme.cross.phase', static fn(): object => new stdClass());
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        EscapeProbe::$captured = $container->get('acme.cross.phase');
    }

    /** @return list<class-string<ServiceProviderInterface>> */
    public function providers(): array
    {
        return [];
    }
}
