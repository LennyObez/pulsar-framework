<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\TenancyConfig;
use Pulsar\Config\TenantDatabaseConfig;
use Pulsar\Tenancy\Exception\TenancyException;
use Pulsar\Tenancy\TenantDatabaseStrategy;
use Pulsar\Tenancy\TenantResolverStrategy;

#[CoversClass(TenancyConfig::class)]
#[CoversClass(TenantDatabaseConfig::class)]
final class TenancyConfigTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('TENANCY_ENABLED');
    }

    protected function tearDown(): void
    {
        putenv('TENANCY_ENABLED');
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $environment = Environment::load();

        $config = TenancyConfig::fromArray([
            'enabled' => true,
            'resolver' => 'subdomain',
            'header_name' => 'X-Custom-Tenant',
            'subdomain_suffix' => '.app.example.com',
            'path_prefix' => '/org/',
            'default_tenant' => 'fallback',
            'database' => [
                'strategy' => 'separate_connection',
                'prefix_template' => 't_{tenant_id}_',
            ],
            'tenants' => [
                'acme' => ['name' => 'Acme Corp'],
                'globex' => ['name' => 'Globex Corporation'],
            ],
        ], $environment);

        self::assertTrue($config->enabled);
        self::assertSame(TenantResolverStrategy::Subdomain, $config->resolver);
        self::assertSame('X-Custom-Tenant', $config->headerName);
        self::assertSame('.app.example.com', $config->subdomainSuffix);
        self::assertSame('/org/', $config->pathPrefix);
        self::assertSame('fallback', $config->defaultTenant);
        self::assertSame(TenantDatabaseStrategy::SeparateConnection, $config->database->strategy);
        self::assertSame('t_{tenant_id}_', $config->database->prefixTemplate);
        self::assertCount(2, $config->tenants);
        self::assertSame('Acme Corp', $config->tenants['acme']['name']);
        self::assertSame('Globex Corporation', $config->tenants['globex']['name']);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $environment = Environment::load();

        $config = TenancyConfig::fromArray([], $environment);

        self::assertFalse($config->enabled);
        self::assertSame(TenantResolverStrategy::Header, $config->resolver);
        self::assertSame('X-Tenant-ID', $config->headerName);
        self::assertSame('', $config->subdomainSuffix);
        self::assertSame('/t/', $config->pathPrefix);
        self::assertNull($config->defaultTenant);
        self::assertSame(TenantDatabaseStrategy::Prefix, $config->database->strategy);
        self::assertSame('tenant_{tenant_id}_', $config->database->prefixTemplate);
        self::assertSame([], $config->tenants);
    }

    #[Test]
    public function environmentVariableOverrideForEnabled(): void
    {
        putenv('TENANCY_ENABLED=true');

        $environment = Environment::load();

        $config = TenancyConfig::fromArray([
            'enabled' => false,
        ], $environment);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function invalidResolverStrategyThrowsTenancyException(): void
    {
        $environment = Environment::load();

        $this->expectException(TenancyException::class);
        $this->expectExceptionMessageIsOrContains('Unknown resolver strategy "redis"');

        (void) TenancyConfig::fromArray(['resolver' => 'redis'], $environment);
    }
}
