<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Config\EncryptionConfig;
use Pulsar\Extension\Orm\Config\MetadataCacheConfig;
use Pulsar\Extension\Orm\Config\OrmConfig;

#[CoversClass(OrmConfig::class)]
#[CoversClass(MetadataCacheConfig::class)]
#[CoversClass(EncryptionConfig::class)]
final class OrmConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = OrmConfig::fromArray([]);

        self::assertSame('default', $config->connection);
        self::assertSame('tenant_id', $config->tenantColumn);
        self::assertSame('deleted_at', $config->softDeleteColumn);

        // Metadata cache defaults
        self::assertSame('array', $config->metadataCache->driver);
        self::assertSame('', $config->metadataCache->path);

        // Encryption defaults
        self::assertFalse($config->encryption->enabled);
        self::assertSame(5, $config->encryption->subKeyId);
        self::assertSame('orm__enc', $config->encryption->context);
        self::assertSame('orm__bidx', $config->encryption->blindIndexContext);
    }

    #[Test]
    public function fromArrayWithOverrides(): void
    {
        $config = OrmConfig::fromArray([
            'connection' => 'primary',
            'tenant_column' => 'org_id',
            'soft_delete_column' => 'removed_at',
            'metadata_cache' => [
                'driver' => 'file',
                'path' => '/tmp/meta',
            ],
            'encryption' => [
                'enabled' => true,
                'sub_key_id' => 10,
                'context' => 'custom__enc',
                'blind_index_context' => 'custom__bidx',
            ],
        ]);

        self::assertSame('primary', $config->connection);
        self::assertSame('org_id', $config->tenantColumn);
        self::assertSame('removed_at', $config->softDeleteColumn);
        self::assertSame('file', $config->metadataCache->driver);
        self::assertSame('/tmp/meta', $config->metadataCache->path);
        self::assertTrue($config->encryption->enabled);
        self::assertSame(10, $config->encryption->subKeyId);
        self::assertSame('custom__enc', $config->encryption->context);
        self::assertSame('custom__bidx', $config->encryption->blindIndexContext);
    }

    #[Test]
    public function encryptionConfigFromArrayDefaults(): void
    {
        $config = EncryptionConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame(5, $config->subKeyId);
        self::assertSame('orm__enc', $config->context);
        self::assertSame('orm__bidx', $config->blindIndexContext);
    }

    #[Test]
    public function metadataCacheConfigFromArrayDefaults(): void
    {
        $config = MetadataCacheConfig::fromArray([]);

        self::assertSame('array', $config->driver);
        self::assertSame('', $config->path);
    }

    #[Test]
    public function metadataCacheConfigFromArrayOverrides(): void
    {
        $config = MetadataCacheConfig::fromArray([
            'driver' => 'redis',
            'path' => '/cache',
        ]);

        self::assertSame('redis', $config->driver);
        self::assertSame('/cache', $config->path);
    }
}
