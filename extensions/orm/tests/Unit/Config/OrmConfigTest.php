<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Config\EncryptionConfig;
use Pulsar\Extension\Orm\Config\MetadataCacheConfig;
use Pulsar\Extension\Orm\Config\OrmConfig;

final class OrmConfigTest extends TestCase
{
    #[Test]
    public function constructionWithExplicitValues(): void
    {
        $metadataCache = new MetadataCacheConfig('file', '/tmp');
        $encryption = new EncryptionConfig(true, 5, 'ctx', 'bidx');

        $config = new OrmConfig(
            connection: 'primary',
            metadataCache: $metadataCache,
            encryption: $encryption,
            tenantColumn: 'org_id',
            softDeleteColumn: 'removed_at',
        );

        self::assertSame('primary', $config->connection);
        self::assertSame($metadataCache, $config->metadataCache);
        self::assertSame($encryption, $config->encryption);
        self::assertSame('org_id', $config->tenantColumn);
        self::assertSame('removed_at', $config->softDeleteColumn);
    }

    #[Test]
    public function fromArrayWithAllKeys(): void
    {
        $config = OrmConfig::fromArray([
            'connection' => 'secondary',
            'metadata_cache' => ['driver' => 'file', 'path' => '/cache'],
            'encryption' => ['enabled' => true, 'sub_key_id' => 3],
            'tenant_column' => 'company_id',
            'soft_delete_column' => 'archived_at',
        ]);

        self::assertSame('secondary', $config->connection);
        self::assertSame('file', $config->metadataCache->driver);
        self::assertSame('/cache', $config->metadataCache->path);
        self::assertTrue($config->encryption->enabled);
        self::assertSame(3, $config->encryption->subKeyId);
        self::assertSame('company_id', $config->tenantColumn);
        self::assertSame('archived_at', $config->softDeleteColumn);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = OrmConfig::fromArray([]);

        self::assertSame('default', $config->connection);
        self::assertSame('array', $config->metadataCache->driver);
        self::assertFalse($config->encryption->enabled);
        self::assertSame('tenant_id', $config->tenantColumn);
        self::assertSame('deleted_at', $config->softDeleteColumn);
    }
}
