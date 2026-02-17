<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminStorageConfig;

#[CoversClass(AdminStorageConfig::class)]
final class AdminStorageConfigTest extends TestCase
{
    #[Test]
    public function from_array_with_defaults(): void
    {
        $config = AdminStorageConfig::fromArray([]);

        self::assertSame('sqlite', $config->driver);
        self::assertNull($config->sqlitePath);
    }

    #[Test]
    public function from_array_with_database_driver(): void
    {
        $config = AdminStorageConfig::fromArray([
            'driver' => 'database',
            'sqlite_path' => '/tmp/admin.db',
        ]);

        self::assertSame('database', $config->driver);
        self::assertSame('/tmp/admin.db', $config->sqlitePath);
    }

    #[Test]
    public function from_array_with_null_path(): void
    {
        $config = AdminStorageConfig::fromArray([
            'driver' => 'sqlite',
            'sqlite_path' => null,
        ]);

        self::assertSame('sqlite', $config->driver);
        self::assertNull($config->sqlitePath);
    }

    #[Test]
    public function constructor_properties_are_accessible(): void
    {
        $config = new AdminStorageConfig(
            driver: 'database',
            sqlitePath: '/var/data/admin.sqlite',
        );

        self::assertSame('database', $config->driver);
        self::assertSame('/var/data/admin.sqlite', $config->sqlitePath);
    }
}
