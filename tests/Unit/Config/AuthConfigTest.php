<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AuthConfig;
use Pulsar\Config\AuthGuardConfig;
use Pulsar\Config\AuthorizationConfig;
use Pulsar\Config\TwoFactorConfig;

#[CoversClass(AuthConfig::class)]
#[CoversClass(AuthGuardConfig::class)]
#[CoversClass(AuthorizationConfig::class)]
#[CoversClass(TwoFactorConfig::class)]
final class AuthConfigTest extends TestCase
{
    #[Test]
    public function defaultsApplied(): void
    {
        $config = AuthConfig::fromArray([]);

        self::assertSame('session', $config->defaultGuard);
        self::assertSame([], $config->guards);
        self::assertFalse($config->twoFactor->enabled);
        self::assertSame('Pulsar', $config->twoFactor->issuer);
        self::assertSame(6, $config->twoFactor->codeDigits);
        self::assertSame(30, $config->twoFactor->codePeriod);
        self::assertSame(1, $config->twoFactor->verificationWindow);
        self::assertSame(8, $config->twoFactor->recoveryCodeCount);
        self::assertSame([], $config->authorization->roles);
        self::assertSame([], $config->authorization->superRoles);
    }

    #[Test]
    public function fromArrayBuildsAllSubConfigs(): void
    {
        $data = [
            'default_guard' => 'token',
            'guards' => [
                ['name' => 'session', 'driver' => 'session', 'enabled' => true],
                ['name' => 'api', 'driver' => 'token', 'enabled' => false],
            ],
            'two_factor' => [
                'enabled' => true,
                'issuer' => 'MyApp',
                'code_digits' => 8,
                'code_period' => 60,
                'verification_window' => 2,
                'recovery_code_count' => 12,
            ],
            'authorization' => [
                'roles' => [
                    'admin' => ['permissions' => ['*']],
                    'user' => ['permissions' => ['read']],
                ],
                'super_roles' => ['admin'],
            ],
        ];

        $config = AuthConfig::fromArray($data);

        self::assertSame('token', $config->defaultGuard);
        self::assertCount(2, $config->guards);

        self::assertSame('session', $config->guards[0]->name);
        self::assertSame('session', $config->guards[0]->driver);
        self::assertTrue($config->guards[0]->enabled);

        self::assertSame('api', $config->guards[1]->name);
        self::assertSame('token', $config->guards[1]->driver);
        self::assertFalse($config->guards[1]->enabled);

        self::assertTrue($config->twoFactor->enabled);
        self::assertSame('MyApp', $config->twoFactor->issuer);
        self::assertSame(8, $config->twoFactor->codeDigits);
        self::assertSame(60, $config->twoFactor->codePeriod);
        self::assertSame(2, $config->twoFactor->verificationWindow);
        self::assertSame(12, $config->twoFactor->recoveryCodeCount);

        self::assertCount(2, $config->authorization->roles);
        self::assertSame(['admin'], $config->authorization->superRoles);
    }

    #[Test]
    public function guardConfigDefaults(): void
    {
        $config = AuthGuardConfig::fromArray([]);

        self::assertSame('', $config->name);
        self::assertSame('session', $config->driver);
        self::assertTrue($config->enabled);
    }

    #[Test]
    public function twoFactorConfigStandalone(): void
    {
        $config = TwoFactorConfig::fromArray([
            'enabled' => true,
            'issuer' => 'Test',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('Test', $config->issuer);
    }

    #[Test]
    public function authorizationConfigStandalone(): void
    {
        $config = AuthorizationConfig::fromArray([
            'roles' => ['editor' => ['level' => 2]],
            'super_roles' => ['superadmin'],
        ]);

        self::assertSame(['editor' => ['level' => 2]], $config->roles);
        self::assertSame(['superadmin'], $config->superRoles);
    }
}
