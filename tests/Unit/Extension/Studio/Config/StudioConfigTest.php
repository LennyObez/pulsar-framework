<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\Studio\Config\StudioCollectorConfig;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Extension\Studio\Config\StudioRetentionConfig;
use Pulsar\Extension\Studio\Config\StudioSecurityConfig;
use Pulsar\Extension\Studio\Config\StudioServerConfig;

#[CoversClass(StudioConfig::class)]
#[CoversClass(StudioCollectorConfig::class)]
#[CoversClass(StudioRetentionConfig::class)]
#[CoversClass(StudioSecurityConfig::class)]
#[CoversClass(StudioServerConfig::class)]
final class StudioConfigTest extends TestCase
{
    #[Test]
    public function defaultConfigValues(): void
    {
        $config = new StudioConfig();

        self::assertTrue($config->enabled);
        self::assertSame('storage/studio/studio.sqlite', $config->storagePath);
        self::assertSame('sqlite', $config->storeBackend);
        self::assertSame(1.0, $config->samplingRate);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $env = Environment::load('/nonexistent/.env');
        $config = StudioConfig::fromArray([], $env);

        self::assertTrue($config->enabled);
        self::assertSame('sqlite', $config->storeBackend);
        self::assertSame(1.0, $config->samplingRate);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $env = Environment::load('/nonexistent/.env');
        $config = StudioConfig::fromArray([
            'enabled' => false,
            'storage_path' => '/custom/path.db',
            'store' => 'database',
            'sampling_rate' => 0.5,
        ], $env);

        self::assertFalse($config->enabled);
        self::assertSame('/custom/path.db', $config->storagePath);
        self::assertSame('database', $config->storeBackend);
        self::assertSame(0.5, $config->samplingRate);
    }

    #[Test]
    public function fromArrayClamsSamplingRate(): void
    {
        $env = Environment::load('/nonexistent/.env');

        $configHigh = StudioConfig::fromArray(['sampling_rate' => 5.0], $env);
        self::assertSame(1.0, $configHigh->samplingRate);

        $configLow = StudioConfig::fromArray(['sampling_rate' => -1.0], $env);
        self::assertSame(0.0, $configLow->samplingRate);
    }

    #[Test]
    public function invalidStoreBackendFallsBackToSqlite(): void
    {
        $env = Environment::load('/nonexistent/.env');
        $config = StudioConfig::fromArray(['store' => 'invalid'], $env);

        self::assertSame('sqlite', $config->storeBackend);
    }

    #[Test]
    public function retentionConfigDefaults(): void
    {
        $config = new StudioRetentionConfig();

        self::assertSame(7, $config->maxAgeDays);
        self::assertSame(500, $config->maxSizeMb);
        self::assertSame(24, $config->vacuumIntervalHours);
    }

    #[Test]
    public function retentionConfigFromArray(): void
    {
        $env = Environment::load('/nonexistent/.env');
        $config = StudioRetentionConfig::fromArray([
            'max_age_days' => 14,
            'max_size_mb' => 1000,
            'vacuum_interval_hours' => 12,
        ], $env);

        self::assertSame(14, $config->maxAgeDays);
        self::assertSame(1000, $config->maxSizeMb);
        self::assertSame(12, $config->vacuumIntervalHours);
    }

    #[Test]
    public function securityConfigDefaults(): void
    {
        $config = new StudioSecurityConfig();

        self::assertFalse($config->authRequired);
        self::assertNull($config->username);
        self::assertNull($config->password);
        self::assertSame(['127.0.0.1/8', '::1/128'], $config->allowedCidrs);
        self::assertFalse($config->productionConfirm);
    }

    #[Test]
    public function securityConfigFromArray(): void
    {
        $env = Environment::load('/nonexistent/.env');
        $config = StudioSecurityConfig::fromArray([
            'auth_required' => true,
            'username' => 'admin',
            'password' => 'secret',
            'allowed_cidrs' => ['10.0.0.0/8'],
            'production_confirm' => true,
        ], $env);

        self::assertTrue($config->authRequired);
        self::assertSame('admin', $config->username);
        self::assertSame('secret', $config->password);
        self::assertSame(['10.0.0.0/8'], $config->allowedCidrs);
        self::assertTrue($config->productionConfirm);
    }

    #[Test]
    public function serverConfigDefaults(): void
    {
        $config = new StudioServerConfig();

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(8585, $config->port);
        self::assertSame('extensions/studio/dev/public', $config->documentRoot);
    }

    #[Test]
    public function serverConfigFromArray(): void
    {
        $env = Environment::load('/nonexistent/.env');
        $config = StudioServerConfig::fromArray([
            'host' => '0.0.0.0',
            'port' => 9090,
            'document_root' => '/var/www/studio',
        ], $env);

        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(9090, $config->port);
        self::assertSame('/var/www/studio', $config->documentRoot);
    }

    #[Test]
    public function collectorConfigDefaults(): void
    {
        $config = new StudioCollectorConfig();

        self::assertTrue($config->http);
        self::assertTrue($config->database);
        self::assertTrue($config->logs);
        self::assertTrue($config->exceptions);
        self::assertTrue($config->scheduler);
        self::assertTrue($config->featureFlags);
        self::assertTrue($config->queue);
        self::assertTrue($config->benchmark);
        self::assertTrue($config->runtime);
        self::assertFalse($config->storeRawSql);
        self::assertFalse($config->redactTableNames);
    }

    #[Test]
    public function collectorConfigFromArrayDisablesCollectors(): void
    {
        $env = Environment::load('/nonexistent/.env');
        $config = StudioCollectorConfig::fromArray([
            'http' => ['enabled' => false],
            'database' => ['enabled' => false, 'store_raw_sql' => true, 'redact_table_names' => true],
            'logs' => ['enabled' => false],
        ], $env);

        self::assertFalse($config->http);
        self::assertFalse($config->database);
        self::assertFalse($config->logs);
        self::assertTrue($config->storeRawSql);
        self::assertTrue($config->redactTableNames);
    }
}
