<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DiskConfig;
use Pulsar\Config\StorageDriver;

#[CoversClass(DiskConfig::class)]
final class DiskConfigTest extends TestCase
{
    #[Test]
    public function it_has_sensible_defaults(): void
    {
        $config = new DiskConfig(name: 'local', driver: StorageDriver::Local);

        self::assertSame('local', $config->name);
        self::assertSame(StorageDriver::Local, $config->driver);
        self::assertSame('', $config->root);
        self::assertSame('private', $config->visibility);
        self::assertSame('', $config->region);
        self::assertSame('', $config->bucket);
        self::assertSame('', $config->prefix);
        self::assertNull($config->endpoint);
        self::assertFalse($config->usePathStyle);
    }

    #[Test]
    public function it_creates_local_disk_from_array(): void
    {
        $result = DiskConfig::fromArray('uploads', [
            'driver' => 'local',
            'root' => 'storage/uploads',
            'visibility' => 'public',
        ]);

        self::assertSame('uploads', $result->name);
        self::assertSame(StorageDriver::Local, $result->driver);
        self::assertSame('storage/uploads', $result->root);
        self::assertSame('public', $result->visibility);
        self::assertSame('', $result->region);
        self::assertSame('', $result->bucket);
        self::assertSame('', $result->prefix);
        self::assertNull($result->endpoint);
        self::assertFalse($result->usePathStyle);
    }

    #[Test]
    public function it_creates_s3_disk_from_array(): void
    {
        $result = DiskConfig::fromArray('s3', [
            'driver' => 's3',
            'root' => '',
            'visibility' => 'private',
            'region' => 'us-east-1',
            'bucket' => 'my-bucket',
            'prefix' => 'app/',
            'endpoint' => 'https://s3.amazonaws.com',
            'use_path_style' => true,
        ]);

        self::assertSame('s3', $result->name);
        self::assertSame(StorageDriver::S3, $result->driver);
        self::assertSame('', $result->root);
        self::assertSame('private', $result->visibility);
        self::assertSame('us-east-1', $result->region);
        self::assertSame('my-bucket', $result->bucket);
        self::assertSame('app/', $result->prefix);
        self::assertSame('https://s3.amazonaws.com', $result->endpoint);
        self::assertTrue($result->usePathStyle);
    }

    #[Test]
    public function it_uses_defaults_when_array_is_empty(): void
    {
        $result = DiskConfig::fromArray('default', []);

        self::assertSame('default', $result->name);
        self::assertSame(StorageDriver::Local, $result->driver);
        self::assertSame('', $result->root);
        self::assertSame('private', $result->visibility);
        self::assertSame('', $result->region);
        self::assertSame('', $result->bucket);
        self::assertSame('', $result->prefix);
        self::assertNull($result->endpoint);
        self::assertFalse($result->usePathStyle);
    }

    #[Test]
    public function it_creates_memory_disk_from_array(): void
    {
        $result = DiskConfig::fromArray('testing', [
            'driver' => 'memory',
        ]);

        self::assertSame('testing', $result->name);
        self::assertSame(StorageDriver::Memory, $result->driver);
    }

    #[Test]
    public function it_defaults_driver_to_local_for_non_string_value(): void
    {
        $result = DiskConfig::fromArray('broken', [
            'driver' => 123,
        ]);

        self::assertSame(StorageDriver::Local, $result->driver);
    }

    #[Test]
    public function it_defaults_string_fields_for_non_string_values(): void
    {
        $result = DiskConfig::fromArray('broken', [
            'driver' => 'local',
            'root' => 123,
            'visibility' => false,
            'region' => [],
            'bucket' => null,
            'prefix' => 456,
            'endpoint' => 789,
        ]);

        self::assertSame('', $result->root);
        self::assertSame('private', $result->visibility);
        self::assertSame('', $result->region);
        self::assertSame('', $result->bucket);
        self::assertSame('', $result->prefix);
        self::assertNull($result->endpoint);
    }

    #[Test]
    public function it_handles_null_endpoint_in_array(): void
    {
        $result = DiskConfig::fromArray('disk', [
            'driver' => 'local',
            'endpoint' => null,
        ]);

        self::assertNull($result->endpoint);
    }
}
