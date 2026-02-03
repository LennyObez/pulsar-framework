<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DiskConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\StorageConfig;
use Pulsar\Config\StorageDriver;

#[CoversClass(StorageConfig::class)]
final class StorageConfigTest extends TestCase
{
    private Environment $environment;

    protected function setUp(): void
    {
        putenv('STORAGE_DISK');
        $this->environment = Environment::load();
    }

    protected function tearDown(): void
    {
        putenv('STORAGE_DISK');
    }

    #[Test]
    public function fromArrayWithDisks(): void
    {
        $data = [
            'default' => 'uploads',
            'disks' => [
                'uploads' => [
                    'driver' => 'local',
                    'root' => '/var/storage/uploads',
                    'visibility' => 'public',
                ],
                'backups' => [
                    'driver' => 's3',
                    'region' => 'us-west-2',
                    'bucket' => 'my-backups',
                    'prefix' => 'app/',
                    'endpoint' => 'https://s3.us-west-2.amazonaws.com',
                    'use_path_style' => true,
                ],
                'cache' => [
                    'driver' => 'memory',
                ],
            ],
        ];

        $config = StorageConfig::fromArray($data, $this->environment);

        self::assertSame('uploads', $config->default);
        self::assertCount(3, $config->disks);

        self::assertArrayHasKey('uploads', $config->disks);
        self::assertInstanceOf(DiskConfig::class, $config->disks['uploads']);
        self::assertSame(StorageDriver::Local, $config->disks['uploads']->driver);
        self::assertSame('/var/storage/uploads', $config->disks['uploads']->root);
        self::assertSame('public', $config->disks['uploads']->visibility);

        self::assertArrayHasKey('backups', $config->disks);
        self::assertSame(StorageDriver::S3, $config->disks['backups']->driver);
        self::assertSame('us-west-2', $config->disks['backups']->region);
        self::assertSame('my-backups', $config->disks['backups']->bucket);
        self::assertSame('app/', $config->disks['backups']->prefix);
        self::assertSame('https://s3.us-west-2.amazonaws.com', $config->disks['backups']->endpoint);
        self::assertTrue($config->disks['backups']->usePathStyle);

        self::assertArrayHasKey('cache', $config->disks);
        self::assertSame(StorageDriver::Memory, $config->disks['cache']->driver);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = StorageConfig::fromArray([], $this->environment);

        self::assertSame('local', $config->default);
        self::assertSame([], $config->disks);
    }

    #[Test]
    public function environmentVariableOverridesDefaultDisk(): void
    {
        putenv('STORAGE_DISK=s3');
        $environment = Environment::load();

        $config = StorageConfig::fromArray([
            'default' => 'local',
        ], $environment);

        self::assertSame('s3', $config->default);
    }

    #[Test]
    public function configArrayDefaultUsedWhenNoEnvironmentVariable(): void
    {
        $config = StorageConfig::fromArray([
            'default' => 'memory',
        ], $this->environment);

        self::assertSame('memory', $config->default);
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $config = new StorageConfig();

        self::assertSame('local', $config->default);
        self::assertSame([], $config->disks);
    }

    #[Test]
    public function diskConfigNamesMatchKeys(): void
    {
        $data = [
            'disks' => [
                'primary' => [
                    'driver' => 'local',
                    'root' => '/tmp/primary',
                ],
            ],
        ];

        $config = StorageConfig::fromArray($data, $this->environment);

        self::assertSame('primary', $config->disks['primary']->name);
    }
}
