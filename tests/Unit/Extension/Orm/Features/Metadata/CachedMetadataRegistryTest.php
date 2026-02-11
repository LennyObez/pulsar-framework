<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\Column;
use Pulsar\Extension\Orm\Attribute\Id;
use Pulsar\Extension\Orm\Attribute\Table;
use Pulsar\Extension\Orm\Config\EncryptionConfig;
use Pulsar\Extension\Orm\Config\MetadataCacheConfig;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Features\Metadata\CachedMetadataRegistry;
use Pulsar\Extension\Orm\Features\Metadata\MetadataCompiler;

#[CoversClass(CachedMetadataRegistry::class)]
final class CachedMetadataRegistryTest extends TestCase
{
    private MetadataCompiler $compiler;

    protected function setUp(): void
    {
        $config = new OrmConfig(
            connection: 'default',
            metadataCache: new MetadataCacheConfig(driver: 'array', path: ''),
            encryption: new EncryptionConfig(enabled: false, subKeyId: 5, context: 'test', blindIndexContext: 'test_bi'),
            tenantColumn: 'tenant_id',
            softDeleteColumn: 'deleted_at',
        );

        $this->compiler = new MetadataCompiler($config);
    }

    #[Test]
    public function getCompilesOnFirstAccessAndCachesSubsequentCalls(): void
    {
        $registry = new CachedMetadataRegistry($this->compiler);

        // First call compiles
        $result1 = $registry->get(CacheTestEntity::class);

        // Second call uses cache -- returns the exact same instance
        $result2 = $registry->get(CacheTestEntity::class);

        self::assertSame(CacheTestEntity::class, $result1->entityClass);
        self::assertSame($result1, $result2);
    }

    #[Test]
    public function hasReturnsTrueForCompilableEntity(): void
    {
        $registry = new CachedMetadataRegistry($this->compiler);

        self::assertTrue($registry->has(CacheTestEntity::class));
    }

    #[Test]
    public function hasReturnsFalseForInvalidEntity(): void
    {
        $registry = new CachedMetadataRegistry($this->compiler);

        // CacheTestNoTable has no #[Table] attribute, so compile will throw
        self::assertFalse($registry->has(CacheTestNoTable::class));
    }

    #[Test]
    public function clearRemovesCachedMetadata(): void
    {
        $registry = new CachedMetadataRegistry($this->compiler);

        $result1 = $registry->get(CacheTestEntity::class);
        $registry->clear();
        $result2 = $registry->get(CacheTestEntity::class);

        // Both should have the same data but be different instances (recompiled)
        self::assertSame(CacheTestEntity::class, $result1->entityClass);
        self::assertSame(CacheTestEntity::class, $result2->entityClass);
        // After clear, a new metadata object is compiled
        self::assertNotSame($result1, $result2);
    }

    #[Test]
    public function warmUpPrecompilesMultipleClasses(): void
    {
        $registry = new CachedMetadataRegistry($this->compiler);

        // Should not throw
        $registry->warmUp([CacheTestEntity::class, CacheTestEntity2::class]);

        // Both should be cached now
        self::assertTrue($registry->has(CacheTestEntity::class));
        self::assertTrue($registry->has(CacheTestEntity2::class));
    }

    #[Test]
    public function hasReturnsTrueForAlreadyCachedEntity(): void
    {
        $registry = new CachedMetadataRegistry($this->compiler);

        // Pre-populate cache
        $registry->get(CacheTestEntity::class);

        // has() should return true from cache
        self::assertTrue($registry->has(CacheTestEntity::class));
    }
}

#[Table(name: 'cache_test')]
class CacheTestEntity
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id;

    #[Column]
    public string $name = '';
}

#[Table(name: 'cache_test_2')]
class CacheTestEntity2
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id;

    #[Column]
    public string $title = '';
}

class CacheTestNoTable
{
    public int $id;
}
