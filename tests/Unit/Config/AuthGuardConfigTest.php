<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AuthGuardConfig;

#[CoversClass(AuthGuardConfig::class)]
final class AuthGuardConfigTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $config = new AuthGuardConfig(name: 'web', driver: 'session', enabled: true);

        self::assertSame('web', $config->name);
        self::assertSame('session', $config->driver);
        self::assertTrue($config->enabled);
    }

    #[Test]
    public function enabledDefaultsToTrue(): void
    {
        $config = new AuthGuardConfig(name: 'api', driver: 'token');

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = AuthGuardConfig::fromArray([
            'name' => 'admin',
            'driver' => 'jwt',
            'enabled' => false,
        ]);

        self::assertSame('admin', $config->name);
        self::assertSame('jwt', $config->driver);
        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingFields(): void
    {
        $config = AuthGuardConfig::fromArray([]);

        self::assertSame('', $config->name);
        self::assertSame('session', $config->driver);
        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayHandlesNonStringName(): void
    {
        $config = AuthGuardConfig::fromArray([
            'name' => 42,
            'driver' => true,
        ]);

        self::assertSame('', $config->name);
        self::assertSame('session', $config->driver);
    }
}
