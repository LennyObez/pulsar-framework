<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Exception\CapabilityDeniedException;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\ExtensionConfigRegistry;
use Pulsar\Extensibility\Internal\ScopedContainerProxy;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Routing\Router;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

use function dirname;
use function in_array;
use function sort;
use function sprintf;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ScopedContainerProxy::class)]
final class ScopedContainerProxyTest extends TestCase
{
    private Container $container;
    private CapabilityPolicy $policy;
    private ServiceRestrictionMap $map;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->policy = CapabilityPolicy::defaults();
        $this->map = ServiceRestrictionMap::defaults();

        // Register some test services
        $this->container->instance(LoggerInterface::class, new NullLogger());
        $this->container->instance('Pulsar\Security\Crypto\MasterKey', new stdClass());
        $this->container->instance('Pulsar\Database\ConnectionInterface', new stdClass());
        $this->container->instance('Pulsar\Security\Audit\AuditSinkInterface', new stdClass());
    }

    /** @param list<ExtensionCapability> $additionalCapabilities */
    private function proxy(TrustTier $tier, array $additionalCapabilities = []): ScopedContainerProxy
    {
        return new ScopedContainerProxy(
            $this->container,
            $tier,
            $this->policy,
            $this->map,
            additionalCapabilities: $additionalCapabilities,
        );
    }

    // --- Core tier: full access ---

    #[Test]
    public function coreCanResolveSafeServices(): void
    {
        $proxy = $this->proxy(TrustTier::Core);

        self::assertInstanceOf(NullLogger::class, $proxy->get(LoggerInterface::class));
    }

    #[Test]
    public function coreCanResolveRestrictedServices(): void
    {
        $proxy = $this->proxy(TrustTier::Core);

        self::assertInstanceOf(stdClass::class, $proxy->get('Pulsar\Security\Crypto\MasterKey'));
    }

    #[Test]
    public function coreCanResolveUnknownServices(): void
    {
        $this->container->instance('Custom\Service', new stdClass());
        $proxy = $this->proxy(TrustTier::Core);

        self::assertInstanceOf(stdClass::class, $proxy->get('Custom\Service'));
    }

    #[Test]
    public function coreCanBindServices(): void
    {
        $proxy = $this->proxy(TrustTier::Core);
        $proxy->bind('test.service', fn() => new stdClass());

        self::assertTrue($proxy->has('test.service'));
    }

    #[Test]
    public function coreCanRegisterSingletons(): void
    {
        $proxy = $this->proxy(TrustTier::Core);
        $proxy->singleton('test.singleton', fn() => new stdClass());

        self::assertTrue($proxy->has('test.singleton'));
    }

    #[Test]
    public function verifiedCanRegisterSingletons(): void
    {
        $proxy = $this->proxy(TrustTier::Verified);
        $proxy->singleton('test.singleton', fn() => new stdClass());

        self::assertTrue($proxy->has('test.singleton'));
    }

    #[Test]
    public function communityWithGrantedContainerWriteCanRegisterSingletons(): void
    {
        $proxy = $this->proxy(TrustTier::Community, [ExtensionCapability::ContainerWrite]);
        $proxy->singleton('test.singleton', fn() => new stdClass());

        self::assertTrue($proxy->has('test.singleton'));
    }

    #[Test]
    public function untrustedCannotRegisterSingletons(): void
    {
        $proxy = $this->proxy(TrustTier::Untrusted);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('ServiceRegister');
        $proxy->singleton('test.singleton', fn() => new stdClass());
    }

    #[Test]
    public function coreCanRegisterInstances(): void
    {
        $proxy = $this->proxy(TrustTier::Core);
        $obj = new stdClass();
        $proxy->instance('test.instance', $obj);

        self::assertSame($obj, $proxy->get('test.instance'));
    }

    // --- Verified tier ---

    #[Test]
    public function verifiedCanResolveSafeServices(): void
    {
        $proxy = $this->proxy(TrustTier::Verified);

        self::assertInstanceOf(NullLogger::class, $proxy->get(LoggerInterface::class));
    }

    #[Test]
    public function verifiedCannotResolveCryptoKeys(): void
    {
        $proxy = $this->proxy(TrustTier::Verified);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('CryptoKeyAccess');

        $_ = $proxy->get('Pulsar\Security\Crypto\MasterKey');
    }

    #[Test]
    public function verifiedCanResolveDatabaseServices(): void
    {
        $proxy = $this->proxy(TrustTier::Verified);

        // Verified has DatabaseRaw capability
        self::assertInstanceOf(stdClass::class, $proxy->get('Pulsar\Database\ConnectionInterface'));
    }

    #[Test]
    public function verifiedCanBindServices(): void
    {
        $proxy = $this->proxy(TrustTier::Verified);
        $proxy->bind('test.service', fn() => new stdClass());

        self::assertTrue($proxy->has('test.service'));
    }

    // --- Community tier ---

    #[Test]
    public function communityCanResolveSafeServices(): void
    {
        $proxy = $this->proxy(TrustTier::Community);

        self::assertInstanceOf(NullLogger::class, $proxy->get(LoggerInterface::class));
    }

    #[Test]
    public function communityCannotResolveCryptoKeys(): void
    {
        $proxy = $this->proxy(TrustTier::Community);

        $this->expectException(CapabilityDeniedException::class);
        $_ = $proxy->get('Pulsar\Security\Crypto\MasterKey');
    }

    #[Test]
    public function communityCannotResolveDatabaseServices(): void
    {
        $proxy = $this->proxy(TrustTier::Community);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('DatabaseRaw');
        $_ = $proxy->get('Pulsar\Database\ConnectionInterface');
    }

    #[Test]
    public function communityCannotResolveAuditSink(): void
    {
        $proxy = $this->proxy(TrustTier::Community);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('AuditSinkAccess');
        $_ = $proxy->get('Pulsar\Security\Audit\AuditSinkInterface');
    }

    #[Test]
    public function communityWithGrantedContainerWriteCanBindServices(): void
    {
        // ContainerWrite was removed from the Community default grant in
        // 1.0.0-rc.12 (it allowed silent override of core security services).
        // A community extension can still bind when the capability is granted
        // explicitly via config/extensions.php — modeled here as an extra cap.
        $proxy = $this->proxy(TrustTier::Community, [ExtensionCapability::ContainerWrite]);
        $proxy->bind('test.service', fn() => new stdClass());

        self::assertTrue($proxy->has('test.service'));
    }

    #[Test]
    public function communityCannotResolveUnknownServices(): void
    {
        $this->container->instance('Custom\Unknown\Service', new stdClass());
        $proxy = $this->proxy(TrustTier::Community);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('not classified');
        $_ = $proxy->get('Custom\Unknown\Service');
    }

    // --- Untrusted tier ---

    #[Test]
    public function untrustedCanResolveSafeServices(): void
    {
        $proxy = $this->proxy(TrustTier::Untrusted);

        self::assertInstanceOf(NullLogger::class, $proxy->get(LoggerInterface::class));
    }

    #[Test]
    public function untrustedCannotBindServices(): void
    {
        $proxy = $this->proxy(TrustTier::Untrusted);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('ServiceRegister');
        $proxy->bind('test.service', fn() => new stdClass());
    }

    #[Test]
    public function untrustedCannotRegisterInstances(): void
    {
        $proxy = $this->proxy(TrustTier::Untrusted);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('ServiceRegister');
        $proxy->instance('test.instance', new stdClass());
    }

    // --- ServiceRegister vs ContainerWrite: register a NEW service is allowed
    //     for Verified/Community; OVERRIDING an existing (core) service is not. ---

    #[Test]
    public function verifiedCanRegisterANewOwnService(): void
    {
        $proxy = $this->proxy(TrustTier::Verified);

        // 'Pulsar\Extension\Foo\FooService' is not bound in setUp() → a NEW id.
        $proxy->bind('Pulsar\Extension\Foo\FooService', fn() => new stdClass());

        self::assertTrue($this->container->has('Pulsar\Extension\Foo\FooService'));
    }

    #[Test]
    public function communityCanRegisterANewOwnService(): void
    {
        $proxy = $this->proxy(TrustTier::Community);

        $proxy->instance('Pulsar\Extension\Bar\BarService', new stdClass());

        self::assertTrue($this->container->has('Pulsar\Extension\Bar\BarService'));
    }

    #[Test]
    public function verifiedCannotOverrideAnExistingCoreService(): void
    {
        $proxy = $this->proxy(TrustTier::Verified);

        // LoggerInterface is already bound in setUp() → rebinding is an OVERRIDE,
        // which is ContainerWrite (Core only). Verified must be denied so it can
        // never hijack a core service.
        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('ContainerWrite');
        $proxy->instance(LoggerInterface::class, new NullLogger());
    }

    #[Test]
    public function communityCannotOverrideAnExistingCoreService(): void
    {
        $proxy = $this->proxy(TrustTier::Community);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('ContainerWrite');
        $proxy->bind(LoggerInterface::class, fn() => new NullLogger());
    }

    // --- ServiceDecorate: Verified may wrap a service (original preserved);
    //     Community may not. ---

    #[Test]
    public function verifiedCanDecorateAService(): void
    {
        $proxy = $this->proxy(TrustTier::Verified);
        $proxy->bind('svc.decorable', static fn(): object => new stdClass());

        // Decorator receives the inner service and returns a wrapper. The gate
        // passes (Verified has ServiceDecorate) and the decorator is registered.
        $proxy->decorate('svc.decorable', static fn(object $inner): object => $inner);

        self::assertTrue($this->container->has('svc.decorable'));
    }

    #[Test]
    public function communityCannotDecorateAService(): void
    {
        $this->container->bind('svc.decorable', fn() => new stdClass());
        $proxy = $this->proxy(TrustTier::Community);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('ServiceDecorate');
        $proxy->decorate('svc.decorable', static fn(object $inner): object => $inner);
    }

    /**
     * Decorating is a resolution, and used to be the one that asked nothing.
     *
     * The decorator is handed THE SERVICE ITSELF as its first argument, so a
     * tier that may not `get('...\\MasterKey')` may not decorate it either.
     * `decorate()` consulted the capability for decorating and never the id, so
     * the answer to "may this extension hold the master key" depended on which
     * method it asked through.
     */
    #[Test]
    public function decoratingCannotDeliverAServiceTheTierCannotResolve(): void
    {
        $this->container->bind('Pulsar\\Security\\Crypto\\MasterKey', fn() => new stdClass());
        $proxy = $this->proxy(TrustTier::Verified);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('CryptoKeyAccess');
        $proxy->decorate('Pulsar\\Security\\Crypto\\MasterKey', static fn(object $i): object => $i);
    }

    #[Test]
    public function untrustedCannotResolveRestrictedServices(): void
    {
        $proxy = $this->proxy(TrustTier::Untrusted);

        $this->expectException(CapabilityDeniedException::class);
        $_ = $proxy->get('Pulsar\Security\Crypto\MasterKey');
    }

    #[Test]
    public function untrustedCannotResolveUnknownServices(): void
    {
        $this->container->instance('Custom\Service', new stdClass());
        $proxy = $this->proxy(TrustTier::Untrusted);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('not classified');
        $_ = $proxy->get('Custom\Service');
    }

    // --- has() answers for the scope ---

    /**
     * `has()` used to report the HOST's binding table and was documented as
     * never lying about existence. It was the lie, and PSR-11 says so: `has()`
     * returns true when the container CAN return an entry, and this one cannot
     * return the master key to a Community extension — it throws.
     *
     * The cost was not theoretical. `if ($c->has($x)) { $c->get($x); }` is the
     * one idiom PSR-11 exists to support and the guard every bundled extension
     * uses to degrade around an optional service; the proxy answered yes and
     * then threw, so `pulsar/cms` could not register at the tier this framework
     * ships it at. An extension cannot write defensive code against a predicate
     * that is wrong.
     */
    #[Test]
    public function hasReturnsFalseForServicesThisScopeWouldRefuse(): void
    {
        $proxy = $this->proxy(TrustTier::Community);

        // Bound in the host container, denied to Community: this scope cannot
        // return it, so it does not claim to have it.
        self::assertFalse($proxy->has('Pulsar\Security\Crypto\MasterKey'));
        self::assertTrue($this->container->has('Pulsar\Security\Crypto\MasterKey'));
    }

    /**
     * Narrowing the host's table, never widening it: a safe-listed id the host
     * has bound is still visible, and `has()` on an id nothing bound is still
     * false.
     */
    #[Test]
    public function hasStillReportsWhatThisScopeCanReturn(): void
    {
        $this->container->instance('Psr\Log\LoggerInterface', new stdClass());
        $proxy = $this->proxy(TrustTier::Community);

        self::assertTrue($proxy->has('Psr\Log\LoggerInterface'));
    }

    /**
     * `has()` and `get()` are the same question, so a denial cannot be reached
     * through a guard that said it was safe.
     */
    #[Test]
    public function hasAndGetNeverDisagree(): void
    {
        $this->container->instance('Custom\Service', new stdClass());
        $proxy = $this->proxy(TrustTier::Community);

        foreach (['Pulsar\Security\Crypto\MasterKey', 'Custom\Service', 'Psr\Log\LoggerInterface'] as $id) {
            if (!$proxy->has($id)) {
                continue;
            }

            // No exception may escape for anything has() vouched for.
            $_ = $proxy->get($id);
        }

        self::assertFalse($proxy->has('Custom\Service'));
    }

    #[Test]
    public function hasReturnsFalseForNonExistentServices(): void
    {
        $proxy = $this->proxy(TrustTier::Community);

        self::assertFalse($proxy->has('NonExistent\Service'));
    }

    // --- Additional capabilities ---

    #[Test]
    public function additionalCapabilitiesGrantExtraAccess(): void
    {
        $proxy = $this->proxy(TrustTier::Community, [ExtensionCapability::DatabaseRaw]);

        // Community normally can't access database, but additional capability grants it
        self::assertInstanceOf(stdClass::class, $proxy->get('Pulsar\Database\ConnectionInterface'));
    }

    // --- Delegation methods with capability guards ---

    #[Test]
    public function forgetInstanceDelegatesToInnerWhenAllowed(): void
    {
        $this->container->instance('test.forget', new stdClass());
        $proxy = $this->proxy(TrustTier::Community, [ExtensionCapability::ContainerWrite]);

        $proxy->forgetInstance('test.forget');

        // After forgetting, the instance should be gone
        self::assertFalse($this->container->has('test.forget'));
    }

    #[Test]
    public function untrustedCannotForgetInstance(): void
    {
        $this->container->instance('test.forget', new stdClass());
        $proxy = $this->proxy(TrustTier::Untrusted);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('ContainerWrite');
        $proxy->forgetInstance('test.forget');
    }

    #[Test]
    public function setResolutionHintsDelegatesToInnerWhenAllowed(): void
    {
        $proxy = $this->proxy(TrustTier::Community, [ExtensionCapability::ContainerWrite]);
        $proxy->setResolutionHints(null);

        // Verify proxy delegates without throwing by checking container is still consistent
        self::assertInstanceOf(ScopedContainerProxy::class, $proxy);
    }

    #[Test]
    public function untrustedCannotSetResolutionHints(): void
    {
        $proxy = $this->proxy(TrustTier::Untrusted);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('ContainerWrite');
        $proxy->setResolutionHints(null);
    }

    #[Test]
    public function getBindingsDelegatesToInnerWhenAllowed(): void
    {
        $this->container->bind('proxy.test', fn() => new stdClass());
        $proxy = $this->proxy(TrustTier::Community);

        $bindings = $proxy->getBindings();
        self::assertContains('proxy.test', $bindings);
    }

    #[Test]
    public function getBindingsDeniedWithoutContainerRead(): void
    {
        $policy = new CapabilityPolicy([]);
        $proxy = new ScopedContainerProxy(
            $this->container,
            TrustTier::Untrusted,
            $policy,
            $this->map,
        );

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('ContainerRead');
        $_ = $proxy->getBindings();
    }

    #[Test]
    public function getInstancesDelegatesToInnerWhenAllowed(): void
    {
        $proxy = $this->proxy(TrustTier::Community);

        $instances = $proxy->getInstances();
        self::assertContains(LoggerInterface::class, $instances);
    }

    #[Test]
    public function getInstancesDeniedWithoutContainerRead(): void
    {
        $policy = new CapabilityPolicy([]);
        $proxy = new ScopedContainerProxy(
            $this->container,
            TrustTier::Untrusted,
            $policy,
            $this->map,
        );

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('ContainerRead');
        $_ = $proxy->getInstances();
    }

    // --- The configuration registry is narrowed, not handed over ---

    /**
     * `ExtensionConfigRegistry` was in no category of the restriction map, so it
     * fell to deny-by-default and every bundled extension below Core lost its
     * own configuration — `pulsar/booking` and `pulsar/payments` inside `boot()`,
     * `pulsar/ai-governance` on the first resolution of any of its contracts.
     * That failure is what a core-tier grant in config/extensions.php was
     * written to route around.
     *
     * Classifying it is only half the repair. ONE registry holds every
     * extension's sections, and a real `payments` section carries a webhook
     * secret and a provider API key, so handing the object over whole would give
     * any tier holding ContainerRead — Untrusted included — every other
     * extension's credentials. The scope narrows it on the way out.
     *
     * @param TrustTier $tier Every tier that receives a scope at all
     */
    #[Test]
    #[DataProvider('sandboxedTiers')]
    public function narrowsTheConfigRegistryToTheSectionsTheExtensionShips(TrustTier $tier): void
    {
        $this->container->instance(ExtensionConfigRegistry::class, new ExtensionConfigRegistry(sections: [
            // The section pulsar/ai-governance ships, named after its
            // config/ai-governance.php with the dash normalised, exactly as
            // ExtensionConfigPublisher names it.
            'ai_governance' => ['own' => true],
            'payments' => ['api_key' => 'sk_live_secret'],
        ]));

        $registry = $this->scopeForAiGovernance($tier)->get(ExtensionConfigRegistry::class);

        self::assertInstanceOf(ExtensionConfigRegistry::class, $registry);
        self::assertSame(['own' => true], $registry->section('ai_governance'), 'its own section must survive');
        self::assertFalse($registry->has('payments'), 'another extension section must not');
        self::assertSame([], $registry->section('payments'));
    }

    #[Test]
    public function leavesTheHostsOwnConfigRegistryIntact(): void
    {
        $shared = new ExtensionConfigRegistry(sections: ['ai_governance' => [], 'payments' => []]);
        $this->container->instance(ExtensionConfigRegistry::class, $shared);

        $_ = $this->scopeForAiGovernance(TrustTier::Verified)->get(ExtensionConfigRegistry::class);

        self::assertTrue($shared->has('payments'), 'narrowing is a view, never a mutation of the shared registry');
    }

    #[Test]
    public function anExtensionWithNoDirectoryOfItsOwnGetsAnEmptyConfigRegistry(): void
    {
        // Added programmatically, as tests do: no manifest path, so no file to
        // anchor ownership to and nothing it can claim. Same answer isOwnCode()
        // gives, for the same reason.
        $this->container->instance(ExtensionConfigRegistry::class, new ExtensionConfigRegistry(sections: [
            'payments' => ['api_key' => 'sk_live_secret'],
        ]));

        $registry = $this->proxy(TrustTier::Verified)->get(ExtensionConfigRegistry::class);

        self::assertInstanceOf(ExtensionConfigRegistry::class, $registry);
        self::assertSame([], $registry->sections());
    }

    /**
     * @return iterable<string, array{TrustTier}>
     */
    public static function sandboxedTiers(): iterable
    {
        yield 'verified' => [TrustTier::Verified];
        yield 'community' => [TrustTier::Community];
        yield 'untrusted' => [TrustTier::Untrusted];
    }

    /**
     * A scope anchored to a real bundled extension directory, which is what
     * decides the sections it owns. `pulsar/ai-governance` ships exactly one
     * config file and its name needs the dash normalising, so it exercises the
     * mapping rather than assuming it.
     */
    private function scopeForAiGovernance(TrustTier $tier): ScopedContainerProxy
    {
        return new ScopedContainerProxy(
            $this->container,
            $tier,
            $this->policy,
            $this->map,
            'pulsar/ai-governance',
            extensionPath: dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'extensions'
                . DIRECTORY_SEPARATOR . 'ai-governance',
        );
    }

    // --- The proxy may not hand back the objects it mediates ---

    /**
     * `Pulsar\Container\ContainerInterface` and `Pulsar\Routing\RouterInterface`
     * were on the restriction map's SAFE list while the Kernel binds both to the
     * real container and the real router. One `get()` therefore returned the
     * unmediated object and every check in this class became optional.
     *
     * @param string $serviceId An id whose resolution would return the sandboxed object
     */
    #[Test]
    #[DataProvider('sandboxDefeatingServices')]
    public function deniesResolvingTheObjectsTheSandboxMediates(string $serviceId): void
    {
        $this->container->instance($serviceId, new stdClass());
        $proxy = $this->proxy(TrustTier::Community);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('bypass every other check');

        $_ = $proxy->get($serviceId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sandboxDefeatingServices(): iterable
    {
        yield 'container contract' => ['Pulsar\Container\ContainerInterface'];
        yield 'advanced container contract' => ['Pulsar\Container\AdvancedContainerInterface'];
        yield 'container class' => ['Pulsar\Container\Container'];
        yield 'router contract' => ['Pulsar\Routing\RouterInterface'];
        yield 'router class' => ['Pulsar\Routing\Router'];
    }

    /**
     * There is no grant that unlocks it — not a tier, not an `additional_capabilities`
     * entry. Holding every capability in the enum still does not get the container,
     * because the denial is an invariant of the proxy and not a policy lookup.
     */
    #[Test]
    public function noCapabilityGrantUnlocksTheContainerItself(): void
    {
        $this->container->instance('Pulsar\Container\ContainerInterface', $this->container);
        $proxy = $this->proxy(TrustTier::Community, ExtensionCapability::cases());

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('No capability grant unlocks this');

        $_ = $proxy->get('Pulsar\Container\ContainerInterface');
    }

    /**
     * The restriction map is data, and data must not be able to switch the
     * sandbox back off — which is precisely how it was off.
     */
    #[Test]
    public function aRestrictionMapCannotReClassifyTheContainerAsSafe(): void
    {
        $this->container->instance('Pulsar\Container\ContainerInterface', $this->container);

        $proxy = new ScopedContainerProxy(
            $this->container,
            TrustTier::Community,
            $this->policy,
            new ServiceRestrictionMap(
                restrictedServices: [],
                safeServices: ['Pulsar\Container\ContainerInterface'],
            ),
        );

        $this->expectException(CapabilityDeniedException::class);

        $_ = $proxy->get('Pulsar\Container\ContainerInterface');
    }

    // --- Every public method is gated ---

    /**
     * Methods that reach the inner container without asserting a capability,
     * and the reason each is allowed to.
     *
     * `has()` is the whole list. PSR-11 requires it to answer truthfully, and a
     * proxy that lied about existence would break every `has()`-then-`get()`
     * caller in the framework for no security gain: the answer is a boolean
     * about a string an extension already holds, and `get()` still refuses.
     * That it stays harmless is not taken on faith here — see
     * {@see self::hasReturnsTrueForDeniedServices()} and
     * {@see self::hasReturnsFalseForNonExistentServices()}.
     */
    private const array UNGATED_BY_DESIGN = ['has'];

    /**
     * Every public method refuses when the extension holds no capability.
     *
     * The escape tests each pin one hole that was found. This pins the SHAPE of
     * all of them: `model()` on the router proxy, and `call()` here, were not
     * subtle bugs but methods that simply never asked. A method added to this
     * class tomorrow will be silently ungated in exactly the same way, and the
     * only defence that scales is enumerating the class rather than the tests.
     *
     * So the list of methods is read from the class, and a method that appears
     * in neither the invocation map nor {@see self::UNGATED_BY_DESIGN} fails
     * here — the author has to state which it is.
     */
    #[Test]
    public function everyPublicMethodIsGated(): void
    {
        $proxy = $this->ungrantedProxy();
        $invocations = $this->invocationsFor($proxy);
        $bindingsBefore = $this->container->getBindings();
        $instancesBefore = $this->container->getInstances();

        foreach (self::publicMethods() as $name) {
            if (in_array($name, self::UNGATED_BY_DESIGN, true)) {
                continue;
            }

            self::assertArrayHasKey($name, $invocations, sprintf(
                'ScopedContainerProxy::%s() is not exercised by this test. Add it to the '
                . 'invocation map, or to UNGATED_BY_DESIGN with the reason it needs no gate.',
                $name,
            ));

            try {
                ($invocations[$name])();

                self::fail(sprintf(
                    'ScopedContainerProxy::%s() reached the inner container without asserting a capability',
                    $name,
                ));
            } catch (CapabilityDeniedException) {
                // Refused, which is the property under test.
            }
        }

        self::assertSame($bindingsBefore, $this->container->getBindings(), 'a denied call bound something');
        self::assertSame($instancesBefore, $this->container->getInstances(), 'a denied call cached an instance');
    }

    /**
     * A proxy for an extension that holds nothing at all.
     *
     * `TrustTier::Untrusted` still carries `ContainerRead` under the default
     * policy, which would let `getBindings()` and `getInstances()` through and
     * make the enumeration above prove less than it claims. An empty policy
     * asks the narrower question this test is actually about: does the method
     * consult the policy at all?
     */
    private function ungrantedProxy(): ScopedContainerProxy
    {
        return new ScopedContainerProxy(
            $this->container,
            TrustTier::Untrusted,
            new CapabilityPolicy([]),
            $this->map,
        );
    }

    /**
     * One call per gated method, each shaped to reach its guard.
     *
     * Arguments are not generated from signatures: several guards only engage
     * for a particular KIND of argument — `call()` is gated through the types
     * its callable asks for, and `construct()` through the constructor of the
     * class named — so a generic builder would produce calls that pass for
     * uninteresting reasons and hide a missing gate.
     *
     * @return array<string, callable(): mixed>
     */
    private function invocationsFor(ScopedContainerProxy $proxy): array
    {
        return [
            'get' => static fn(): mixed => $proxy->get('Acme\Unclassified\Service'),
            'bind' => static fn(): null => $proxy->bind('acme.gate', static fn(): object => new stdClass()),
            'singleton' => static fn(): null => $proxy->singleton('acme.gate', static fn(): object => new stdClass()),
            'instance' => static fn(): null => $proxy->instance('acme.gate', new stdClass()),
            'decorate' => static fn(): null => $proxy->decorate(
                LoggerInterface::class,
                static fn(object $service): object => $service,
            ),
            'forgetInstance' => static fn(): null => $proxy->forgetInstance(LoggerInterface::class),
            'setResolutionHints' => static fn(): null => $proxy->setResolutionHints(null),
            'getBindings' => static fn(): array => $proxy->getBindings(),
            'getInstances' => static fn(): array => $proxy->getInstances(),
            // Gated through its parameter types, which is where the hole was:
            // the method itself asserted nothing and let the real container
            // autowire whatever the closure asked for.
            'call' => static fn(): mixed => $proxy->call(
                static fn(ContainerInterface $real): ContainerInterface => $real,
            ),
            // Gated through the constructor it fills — which it fills through
            // get(), so the denial is get()'s denial re-reported against the
            // class. This is what replaced the four-hop prediction that used to
            // be asked before the container autowired the same class anyway.
            'construct' => static fn(): mixed => $proxy->construct(GateProbeWantsContainer::class),
            // Gated at the moment the bound factory runs, for the same reason:
            // it binds construct(), and nothing is constructed until something
            // resolves the id.
            'provideThroughScope' => static function () use ($proxy): mixed {
                $proxy->provideThroughScope(GateProbeWantsContainer::class);

                return $proxy->get(GateProbeWantsContainer::class);
            },
            // Gated by the router proxy it returns, which refuses every
            // registration for a tier without RouteRegister.
            'scopedRouter' => static fn(): mixed => $proxy->scopedRouter(new Router())
                ->get('/probe', static fn(): string => 'ok'),
        ];
    }

    /**
     * Every public, non-static, non-magic method of the proxy.
     *
     * Read from the class rather than from a list, so the enumeration cannot
     * fall behind the class it describes.
     *
     * @return list<string>
     */
    private static function publicMethods(): array
    {
        $methods = [];

        foreach (new ReflectionClass(ScopedContainerProxy::class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($method->isStatic() || $method->isConstructor() || str_starts_with($name, '__')) {
                continue;
            }

            $methods[] = $name;
        }

        sort($methods);

        return $methods;
    }
}

/**
 * A class whose construction could only be satisfied by injecting the real
 * container — the shape {@see ScopedContainerProxy::construct()} refuses,
 * because it fills that parameter through `get()` and `get()` refuses it.
 */
final readonly class GateProbeWantsContainer
{
    public function __construct(public ContainerInterface $container) {}
}
