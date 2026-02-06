<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy;

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
}
