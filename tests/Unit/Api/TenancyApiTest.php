<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Tenancy\Exception\TenancyException;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantDatabaseStrategy;
use Pulsar\Tenancy\TenantResolverInterface;
use Pulsar\Tenancy\TenantResolverStrategy;

#[CoversClass(Api::class)]
final class TenancyApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function tenantResolverInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(TenantResolverInterface::class);
    }

    #[Test]
    public function tenantIsPublicApi(): void
    {
        self::assertHasApiAttribute(Tenant::class);
        self::assertClassIsReadonly(Tenant::class);
    }

    #[Test]
    public function tenantHasFromArrayFactory(): void
    {
        self::assertStaticFactoryExists(Tenant::class, 'fromArray');
    }

    #[Test]
    public function tenantDatabaseStrategyIsPublicApi(): void
    {
        self::assertHasApiAttribute(TenantDatabaseStrategy::class);
    }

    #[Test]
    public function tenantResolverStrategyIsPublicApi(): void
    {
        self::assertHasApiAttribute(TenantResolverStrategy::class);
    }

    #[Test]
    public function tenancyExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(TenancyException::class);
    }

    #[Test]
    public function tenancyExceptionHasStaticFactories(): void
    {
        self::assertStaticFactoryExists(TenancyException::class, 'tenantNotResolved');
        self::assertStaticFactoryExists(TenancyException::class, 'tenantNotFound');
        self::assertStaticFactoryExists(TenancyException::class, 'invalidConfiguration');
    }
}
