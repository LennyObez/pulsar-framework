<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminPaginationConfig;
use Pulsar\Extension\Admin\Config\AdminRateLimitConfig;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Config\AdminSecurityConfig;
use Pulsar\Extension\Admin\Config\AdminStorageConfig;

final class AdminSubConfigTest extends TestCase
{
    // --- AdminRateLimitConfig ---

    #[Test]
    public function rate_limit_defaults(): void
    {
        $config = AdminRateLimitConfig::fromArray([]);

        self::assertSame(120, $config->readLimit);
        self::assertSame(30, $config->writeLimit);
        self::assertSame(5, $config->exportLimit);
        self::assertSame(60, $config->windowSeconds);
    }

    #[Test]
    public function rate_limit_custom_values(): void
    {
        $config = AdminRateLimitConfig::fromArray([
            'read_limit' => 200,
            'write_limit' => 50,
            'export_limit' => 10,
            'window_seconds' => 120,
        ]);

        self::assertSame(200, $config->readLimit);
        self::assertSame(50, $config->writeLimit);
        self::assertSame(10, $config->exportLimit);
        self::assertSame(120, $config->windowSeconds);
    }

    // --- AdminSchemaConfig ---

    #[Test]
    public function schema_config_defaults(): void
    {
        $config = AdminSchemaConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame(['create', 'alter', 'drop', 'rename'], $config->allowedOperations);
        self::assertSame(['admin_', 'pulsar_', 'sqlite_', 'studio_'], $config->denyTablePrefixes);
        self::assertSame(['drop', 'rename', 'drop_column', 'drop_index'], $config->requireStepUpFor);
    }

    #[Test]
    public function schema_config_custom_values(): void
    {
        $config = AdminSchemaConfig::fromArray([
            'enabled' => true,
            'allowed_operations' => ['create', 'alter'],
            'deny_table_prefixes' => ['sys_'],
            'require_step_up_for' => ['drop'],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(['create', 'alter'], $config->allowedOperations);
        self::assertSame(['sys_'], $config->denyTablePrefixes);
        self::assertSame(['drop'], $config->requireStepUpFor);
    }

    // --- AdminStorageConfig ---

    #[Test]
    public function storage_config_defaults(): void
    {
        $config = AdminStorageConfig::fromArray([]);

        self::assertSame('sqlite', $config->driver);
        self::assertNull($config->sqlitePath);
    }

    #[Test]
    public function storage_config_custom_values(): void
    {
        $config = AdminStorageConfig::fromArray([
            'driver' => 'database',
            'sqlite_path' => '/tmp/admin.db',
        ]);

        self::assertSame('database', $config->driver);
        self::assertSame('/tmp/admin.db', $config->sqlitePath);
    }

    // --- AdminSecurityConfig (edge cases) ---

    #[Test]
    public function security_config_partial_override(): void
    {
        $config = AdminSecurityConfig::fromArray([
            'required_role' => 'manager',
        ]);

        self::assertSame('manager', $config->requiredRole);
        self::assertTrue($config->require2fa);
        self::assertTrue($config->csrfRotation);
        self::assertTrue($config->cspNonce);
    }

    // --- AdminPaginationConfig (edge cases) ---

    #[Test]
    public function pagination_config_partial_override(): void
    {
        $config = AdminPaginationConfig::fromArray([
            'default_per_page' => 15,
        ]);

        self::assertSame(15, $config->defaultPerPage);
        self::assertSame(100, $config->maxPerPage);
    }
}
