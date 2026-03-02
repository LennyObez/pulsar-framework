<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tenancy\Exception\TenancyException;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantContext;

#[CoversClass(TenantContext::class)]
final class TenantContextTest extends TestCase
{
    #[Test]
    public function isResolvedReturnsFalseInitially(): void
    {
        $context = new TenantContext();

        self::assertFalse($context->isResolved());
    }

    #[Test]
    public function setAndGetWorkTogether(): void
    {
        $context = new TenantContext();
        $tenant = new Tenant(id: 'acme', name: 'Acme Corp');

        $context->set($tenant);

        self::assertSame($tenant, $context->get());
    }

    #[Test]
    public function tryGetReturnsNullWhenNotSet(): void
    {
        $context = new TenantContext();

        self::assertNull($context->tryGet());
    }

    #[Test]
    public function tryGetReturnsTenantWhenSet(): void
    {
        $context = new TenantContext();
        $tenant = new Tenant(id: 'acme', name: 'Acme Corp');

        $context->set($tenant);

        self::assertSame($tenant, $context->tryGet());
    }

    #[Test]
    public function getThrowsTenancyExceptionWhenNotSet(): void
    {
        $context = new TenantContext();

        $this->expectException(TenancyException::class);

        $_ = $context->get();
    }

    #[Test]
    public function clearRemovesTenant(): void
    {
        $context = new TenantContext();
        $tenant = new Tenant(id: 'acme', name: 'Acme Corp');

        $context->set($tenant);
        self::assertTrue($context->isResolved());

        $context->clear();

        self::assertFalse($context->isResolved());
        self::assertNull($context->tryGet());
    }

    #[Test]
    public function isResolvedReturnsTrueAfterSet(): void
    {
        $context = new TenantContext();
        $tenant = new Tenant(id: 'acme', name: 'Acme Corp');

        $context->set($tenant);

        self::assertTrue($context->isResolved());
    }

    #[Test]
    public function fibersDoNotShareTenant(): void
    {
        $context = new TenantContext();
        $rootTenant = new Tenant(id: 'root', name: 'Root');
        $context->set($rootTenant);

        $fiberTenant = new Tenant(id: 'fiber', name: 'Fiber');
        $observed = null;

        $fiber = new Fiber(function () use ($context, $fiberTenant, &$observed): void {
            $observed = ['initial' => $context->tryGet()];
            $context->set($fiberTenant);
            $observed['fiber_set'] = $context->tryGet();
        });

        $fiber->start();

        self::assertSame($rootTenant, $context->tryGet());
        self::assertNull($observed['initial']);
        self::assertSame($fiberTenant, $observed['fiber_set']);
    }

    #[Test]
    public function clearOnlyAffectsCurrentFiber(): void
    {
        $context = new TenantContext();
        $rootTenant = new Tenant(id: 'root', name: 'Root');
        $context->set($rootTenant);

        $fiber = new Fiber(function () use ($context): void {
            $context->set(new Tenant(id: 'fiber', name: 'Fiber'));
            $context->clear();
        });

        $fiber->start();

        self::assertSame($rootTenant, $context->tryGet());
    }

    #[Test]
    public function twoFibersSeeIndependentTenants(): void
    {
        $context = new TenantContext();
        $tenantA = new Tenant(id: 'a', name: 'A');
        $tenantB = new Tenant(id: 'b', name: 'B');

        $fiberA = new Fiber(function () use ($context, $tenantA): mixed {
            $context->set($tenantA);
            Fiber::suspend();

            return $context->tryGet();
        });

        $fiberB = new Fiber(function () use ($context, $tenantB): mixed {
            $context->set($tenantB);
            Fiber::suspend();

            return $context->tryGet();
        });

        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();
        $fiberB->resume();

        self::assertSame($tenantA, $fiberA->getReturn());
        self::assertSame($tenantB, $fiberB->getReturn());
    }
}
