<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AuthorizationConfig;

#[CoversClass(AuthorizationConfig::class)]
final class AuthorizationConfigTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $config = new AuthorizationConfig();

        self::assertSame([], $config->roles);
        self::assertSame([], $config->superRoles);
    }

    #[Test]
    public function fromArrayWithRolesAndSuperRoles(): void
    {
        $config = AuthorizationConfig::fromArray([
            'roles' => [
                'admin' => ['permissions' => ['*']],
                'editor' => ['permissions' => ['content.edit']],
            ],
            'super_roles' => ['admin'],
        ]);

        self::assertCount(2, $config->roles);
        self::assertArrayHasKey('admin', $config->roles);
        self::assertSame(['admin'], $config->superRoles);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingFields(): void
    {
        $config = AuthorizationConfig::fromArray([]);

        self::assertSame([], $config->roles);
        self::assertSame([], $config->superRoles);
    }
}
