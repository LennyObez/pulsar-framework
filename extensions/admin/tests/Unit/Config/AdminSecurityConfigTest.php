<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminSecurityConfig;

#[CoversClass(AdminSecurityConfig::class)]
final class AdminSecurityConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = AdminSecurityConfig::fromArray([]);

        self::assertSame('admin', $config->requiredRole);
        self::assertTrue($config->require2fa);
        self::assertTrue($config->csrfRotation);
        self::assertTrue($config->cspNonce);
    }

    #[Test]
    public function fromArrayWithAllDisabled(): void
    {
        $config = AdminSecurityConfig::fromArray([
            'required_role' => 'viewer',
            'require_2fa' => false,
            'csrf_rotation' => false,
            'csp_nonce' => false,
        ]);

        self::assertSame('viewer', $config->requiredRole);
        self::assertFalse($config->require2fa);
        self::assertFalse($config->csrfRotation);
        self::assertFalse($config->cspNonce);
    }

    #[Test]
    public function constructorPropertiesAreAccessible(): void
    {
        $config = new AdminSecurityConfig(
            requiredRole: 'superadmin',
            require2fa: true,
            csrfRotation: false,
            cspNonce: true,
        );

        self::assertSame('superadmin', $config->requiredRole);
        self::assertTrue($config->require2fa);
        self::assertFalse($config->csrfRotation);
        self::assertTrue($config->cspNonce);
    }
}
