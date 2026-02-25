<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Config\AdminPaginationConfig;
use Pulsar\Extension\Admin\Config\AdminSecurityConfig;

final class AdminConfigTest extends TestCase
{
    #[Test]
    public function from_array_with_defaults(): void
    {
        $config = AdminConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('/admin', $config->routePrefix);
    }

    #[Test]
    public function from_array_with_custom_values(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'route_prefix' => '/cp',
            'security' => ['required_role' => 'superadmin', 'require_2fa' => false],
            'pagination' => ['default_per_page' => 50, 'max_per_page' => 200],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('/cp', $config->routePrefix);
        self::assertSame('superadmin', $config->security->requiredRole);
        self::assertFalse($config->security->require2fa);
        self::assertSame(50, $config->pagination->defaultPerPage);
        self::assertSame(200, $config->pagination->maxPerPage);
    }

    #[Test]
    public function security_config_defaults(): void
    {
        $config = AdminSecurityConfig::fromArray([]);

        self::assertSame('admin', $config->requiredRole);
        self::assertTrue($config->require2fa);
        self::assertTrue($config->csrfRotation);
        self::assertTrue($config->cspNonce);
    }

    #[Test]
    public function security_config_custom(): void
    {
        $config = AdminSecurityConfig::fromArray([
            'required_role' => 'editor',
            'require_2fa' => false,
            'csrf_rotation' => false,
            'csp_nonce' => false,
        ]);

        self::assertSame('editor', $config->requiredRole);
        self::assertFalse($config->require2fa);
        self::assertFalse($config->csrfRotation);
        self::assertFalse($config->cspNonce);
    }

    #[Test]
    public function pagination_config_defaults(): void
    {
        $config = AdminPaginationConfig::fromArray([]);

        self::assertSame(25, $config->defaultPerPage);
        self::assertSame(100, $config->maxPerPage);
    }

    #[Test]
    public function pagination_config_custom(): void
    {
        $config = AdminPaginationConfig::fromArray([
            'default_per_page' => 10,
            'max_per_page' => 50,
        ]);

        self::assertSame(10, $config->defaultPerPage);
        self::assertSame(50, $config->maxPerPage);
    }
}
