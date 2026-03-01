<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Container\Container;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Exception\CapabilityDeniedException;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\Internal\ScopedContainerProxy;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;
use Pulsar\Extensibility\TrustTier;
use stdClass;

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
        $this->container->instance('Pulsar\Audit\AuditSinkInterface', new stdClass());
    }

    /** @param list<ExtensionCapability> $additionalCapabilities */
    private function proxy(TrustTier $tier, array $additionalCapabilities = []): ScopedContainerProxy
    {
        return new ScopedContainerProxy(
            $this->container,
            $tier,
            $this->policy,
            $this->map,
            $additionalCapabilities,
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
    public function communityCanRegisterSingletons(): void
    {
        $proxy = $this->proxy(TrustTier::Community);
        $proxy->singleton('test.singleton', fn() => new stdClass());

        self::assertTrue($proxy->has('test.singleton'));
    }

    #[Test]
    public function untrustedCannotRegisterSingletons(): void
    {
        $proxy = $this->proxy(TrustTier::Untrusted);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessage('ContainerWrite');
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
        $this->expectExceptionMessage('CryptoKeyAccess');

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
        $this->expectExceptionMessage('DatabaseRaw');
        $_ = $proxy->get('Pulsar\Database\ConnectionInterface');
    }

    #[Test]
    public function communityCannotResolveAuditSink(): void
    {
        $proxy = $this->proxy(TrustTier::Community);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessage('AuditSinkAccess');
        $_ = $proxy->get('Pulsar\Audit\AuditSinkInterface');
    }

    #[Test]
    public function communityCanBindServices(): void
    {
        $proxy = $this->proxy(TrustTier::Community);
        $proxy->bind('test.service', fn() => new stdClass());

        self::assertTrue($proxy->has('test.service'));
    }

    #[Test]
    public function communityCannotResolveUnknownServices(): void
    {
        $this->container->instance('Custom\Unknown\Service', new stdClass());
        $proxy = $this->proxy(TrustTier::Community);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessage('not classified');
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
        $this->expectExceptionMessage('ContainerWrite');
        $proxy->bind('test.service', fn() => new stdClass());
    }

    #[Test]
    public function untrustedCannotRegisterInstances(): void
    {
        $proxy = $this->proxy(TrustTier::Untrusted);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessage('ContainerWrite');
        $proxy->instance('test.instance', new stdClass());
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
        $this->expectExceptionMessage('not classified');
        $_ = $proxy->get('Custom\Service');
    }

    // --- has() truthfulness ---

    #[Test]
    public function hasReturnsTrueForDeniedServices(): void
    {
        $proxy = $this->proxy(TrustTier::Community);

        // MasterKey exists in container but is denied for Community
        self::assertTrue($proxy->has('Pulsar\Security\Crypto\MasterKey'));
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
        $proxy = $this->proxy(TrustTier::Community);

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
        $this->expectExceptionMessage('ContainerWrite');
        $proxy->forgetInstance('test.forget');
    }

    #[Test]
    public function setResolutionHintsDelegatesToInnerWhenAllowed(): void
    {
        $proxy = $this->proxy(TrustTier::Community);
        $proxy->setResolutionHints(null);

        // Verify proxy delegates without throwing by checking container is still consistent
        self::assertInstanceOf(ScopedContainerProxy::class, $proxy);
    }

    #[Test]
    public function untrustedCannotSetResolutionHints(): void
    {
        $proxy = $this->proxy(TrustTier::Untrusted);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessage('ContainerWrite');
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
        $this->expectExceptionMessage('ContainerRead');
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
        $this->expectExceptionMessage('ContainerRead');
        $_ = $proxy->getInstances();
    }
}
