<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Exception\MappingException;
use Pulsar\Extension\Orm\Features\Metadata\MetadataCompiler;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\PostEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\SecureEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity;
use stdClass;

final class MetadataCompilerTest extends TestCase
{
    private MetadataCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new MetadataCompiler(OrmConfig::fromArray([]));
    }

    #[Test]
    public function compileExtractsTableName(): void
    {
        $metadata = $this->compiler->compile(UserEntity::class);

        self::assertSame('users', $metadata->tableName);
    }

    #[Test]
    public function compileExtractsPrimaryKey(): void
    {
        $metadata = $this->compiler->compile(UserEntity::class);

        self::assertInstanceOf(\Pulsar\Extension\Orm\Domain\ColumnMetadata::class, $metadata->primaryKey);
        self::assertTrue($metadata->primaryKey->isPrimaryKey);
    }

    #[Test]
    public function compileExtractsColumns(): void
    {
        $metadata = $this->compiler->compile(UserEntity::class);

        self::assertNotEmpty($metadata->columns);
    }

    #[Test]
    public function compileExtractsRelations(): void
    {
        $metadata = $this->compiler->compile(UserEntity::class);

        self::assertNotEmpty($metadata->relations);
    }

    #[Test]
    public function compileDetectsTimestamps(): void
    {
        $metadata = $this->compiler->compile(UserEntity::class);

        self::assertTrue($metadata->hasTimestamps);
        self::assertSame('created_at', $metadata->createdAtColumn);
        self::assertSame('updated_at', $metadata->updatedAtColumn);
    }

    #[Test]
    public function compileDetectsSoftDelete(): void
    {
        $metadata = $this->compiler->compile(PostEntity::class);

        self::assertTrue($metadata->hasSoftDelete);
        self::assertSame('deleted_at', $metadata->softDeleteColumn);
    }

    #[Test]
    public function compileThrowsForMissingTable(): void
    {
        $this->expectException(MappingException::class);

        $this->compiler->compile(stdClass::class);
    }

    #[Test]
    public function compileDetectsEncryptedColumns(): void
    {
        $metadata = $this->compiler->compile(SecureEntity::class);

        self::assertNotEmpty($metadata->encryptedColumns);
    }

    #[Test]
    public function compileReturnsEntityMetadataInstance(): void
    {
        $metadata = $this->compiler->compile(UserEntity::class);

        self::assertInstanceOf(EntityMetadata::class, $metadata);
        self::assertSame(UserEntity::class, $metadata->entityClass);
    }
}
