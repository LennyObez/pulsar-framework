<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Config\AdminPaginationConfig;
use Pulsar\Extension\Admin\Config\AdminRateLimitConfig;
use Pulsar\Extension\Admin\Config\AdminSecurityConfig;
use Pulsar\Extension\Admin\Config\AdminStorageConfig;

#[CoversClass(AdminConfig::class)]
#[CoversClass(AdminSecurityConfig::class)]
#[CoversClass(AdminPaginationConfig::class)]
#[CoversClass(AdminRateLimitConfig::class)]
#[CoversClass(AdminStorageConfig::class)]
final class AdminConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = AdminConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('/admin', $config->routePrefix);

        // Security defaults
        self::assertSame('admin', $config->security->requiredRole);
        self::assertTrue($config->security->require2fa);
        self::assertTrue($config->security->csrfRotation);
        self::assertTrue($config->security->cspNonce);

        // Pagination defaults
        self::assertSame(25, $config->pagination->defaultPerPage);
        self::assertSame(100, $config->pagination->maxPerPage);

        // Rate limit defaults
        self::assertSame(120, $config->rateLimit->readLimit);
        self::assertSame(30, $config->rateLimit->writeLimit);
        self::assertSame(5, $config->rateLimit->exportLimit);
        self::assertSame(60, $config->rateLimit->windowSeconds);

        // Storage defaults
        self::assertSame('sqlite', $config->storage->driver);
        self::assertNull($config->storage->sqlitePath);
    }

    #[Test]
    public function fromArrayWithOverrides(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'route_prefix' => '/dashboard',
            'security' => [
                'required_role' => 'superadmin',
                'require_2fa' => false,
                'csrf_rotation' => false,
                'csp_nonce' => false,
            ],
            'pagination' => [
                'default_per_page' => 50,
                'max_per_page' => 200,
            ],
            'rate_limit' => [
                'read_limit' => 60,
                'write_limit' => 15,
                'export_limit' => 2,
                'window_seconds' => 120,
            ],
            'storage' => [
                'driver' => 'database',
                'sqlite_path' => '/tmp/admin.db',
            ],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('/dashboard', $config->routePrefix);
        self::assertSame('superadmin', $config->security->requiredRole);
        self::assertFalse($config->security->require2fa);
        self::assertFalse($config->security->csrfRotation);
        self::assertFalse($config->security->cspNonce);
        self::assertSame(50, $config->pagination->defaultPerPage);
        self::assertSame(200, $config->pagination->maxPerPage);
        self::assertSame(60, $config->rateLimit->readLimit);
        self::assertSame(15, $config->rateLimit->writeLimit);
        self::assertSame(2, $config->rateLimit->exportLimit);
        self::assertSame(120, $config->rateLimit->windowSeconds);
        self::assertSame('database', $config->storage->driver);
        self::assertSame('/tmp/admin.db', $config->storage->sqlitePath);
    }

    #[Test]
    public function securityConfigFromArrayDefaults(): void
    {
        $config = AdminSecurityConfig::fromArray([]);

        self::assertSame('admin', $config->requiredRole);
        self::assertTrue($config->require2fa);
        self::assertTrue($config->csrfRotation);
        self::assertTrue($config->cspNonce);
    }

    #[Test]
    public function paginationConfigFromArrayDefaults(): void
    {
        $config = AdminPaginationConfig::fromArray([]);

        self::assertSame(25, $config->defaultPerPage);
        self::assertSame(100, $config->maxPerPage);
    }

    #[Test]
    public function rateLimitConfigFromArrayDefaults(): void
    {
        $config = AdminRateLimitConfig::fromArray([]);

        self::assertSame(120, $config->readLimit);
        self::assertSame(30, $config->writeLimit);
        self::assertSame(5, $config->exportLimit);
        self::assertSame(60, $config->windowSeconds);
    }

    #[Test]
    public function storageConfigFromArrayDefaults(): void
    {
        $config = AdminStorageConfig::fromArray([]);

        self::assertSame('sqlite', $config->driver);
        self::assertNull($config->sqlitePath);
    }
}
