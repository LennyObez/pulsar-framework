<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\TenantDatabaseConfig;
use Pulsar\Tenancy\Exception\TenancyException;
use Pulsar\Tenancy\TenantDatabaseStrategy;

#[CoversClass(TenantDatabaseConfig::class)]
final class TenantDatabaseConfigTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $config = new TenantDatabaseConfig();

        self::assertSame(TenantDatabaseStrategy::Prefix, $config->strategy);
        self::assertSame('tenant_{tenant_id}_', $config->prefixTemplate);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = TenantDatabaseConfig::fromArray([
            'strategy' => 'separate_connection',
            'prefix_template' => 'org_{tenant_id}_',
        ]);

        self::assertSame(TenantDatabaseStrategy::SeparateConnection, $config->strategy);
        self::assertSame('org_{tenant_id}_', $config->prefixTemplate);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingFields(): void
    {
        $config = TenantDatabaseConfig::fromArray([]);

        self::assertSame(TenantDatabaseStrategy::Prefix, $config->strategy);
        self::assertSame('tenant_{tenant_id}_', $config->prefixTemplate);
    }

    #[Test]
    public function invalidStrategyThrowsTenancyException(): void
    {
        $this->expectException(TenancyException::class);
        $this->expectExceptionMessageIsOrContains('Unknown database strategy "sharded"');

        (void) TenantDatabaseConfig::fromArray(['strategy' => 'sharded']);
    }
}
