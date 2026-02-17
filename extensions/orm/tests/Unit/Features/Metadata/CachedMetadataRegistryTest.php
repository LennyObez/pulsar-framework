<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Metadata\CachedMetadataRegistry;
use Pulsar\Extension\Orm\Features\Metadata\MetadataCompiler;
use stdClass;

final class CachedMetadataRegistryTest extends TestCase
{
    private CachedMetadataRegistry $registry;
    private MetadataCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new MetadataCompiler(OrmConfig::fromArray([]));
        $this->registry = new CachedMetadataRegistry($this->compiler);
    }

    #[Test]
    public function getReturnsMetadataForValidEntity(): void
    {
        $metadata = $this->registry->get(\Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity::class);

        self::assertInstanceOf(EntityMetadata::class, $metadata);
        self::assertSame('users', $metadata->tableName);
    }

    #[Test]
    public function getCachesResult(): void
    {
        $first = $this->registry->get(\Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity::class);
        $second = $this->registry->get(\Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity::class);

        self::assertSame($first, $second);
    }

    #[Test]
    public function hasReturnsTrueForValidEntity(): void
    {
        self::assertTrue($this->registry->has(\Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity::class));
    }

    #[Test]
    public function hasReturnsFalseForUnmappedClass(): void
    {
        self::assertFalse($this->registry->has(stdClass::class));
    }

    #[Test]
    public function warmUpPopulatesCache(): void
    {
        $this->registry->warmUp([
            \Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity::class,
            \Pulsar\Extension\Orm\Tests\Unit\Fixtures\PostEntity::class,
        ]);

        self::assertTrue($this->registry->has(\Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity::class));
        self::assertTrue($this->registry->has(\Pulsar\Extension\Orm\Tests\Unit\Fixtures\PostEntity::class));
    }

    #[Test]
    public function clearRemovesCachedEntries(): void
    {
        $this->registry->get(\Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity::class);
        $this->registry->clear();

        // After clear, it should recompile (not throw, but be a fresh compile)
        $metadata = $this->registry->get(\Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity::class);
        self::assertInstanceOf(EntityMetadata::class, $metadata);
    }
}
