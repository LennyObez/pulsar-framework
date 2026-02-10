<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\Column;
use Pulsar\Extension\Orm\Attribute\Encrypted;
use Pulsar\Extension\Orm\Attribute\Id;
use Pulsar\Extension\Orm\Attribute\Relation;
use Pulsar\Extension\Orm\Attribute\SoftDelete;
use Pulsar\Extension\Orm\Attribute\Table;
use Pulsar\Extension\Orm\Attribute\Timestamps;
use Pulsar\Extension\Orm\Attribute\Version;
use Pulsar\Extension\Orm\Config\EncryptionConfig;
use Pulsar\Extension\Orm\Config\MetadataCacheConfig;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Exception\MappingException;
use Pulsar\Extension\Orm\Features\Metadata\MetadataCompiler;

#[CoversClass(MetadataCompiler::class)]
final class MetadataCompilerTest extends TestCase
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
    public function compileBasicEntity(): void
    {
        $metadata = $this->compiler->compile(CompilerTestUser::class);

        self::assertSame(CompilerTestUser::class, $metadata->entityClass);
        self::assertSame('users', $metadata->tableName);
        self::assertNull($metadata->schema);
        self::assertSame('id', $metadata->primaryKey->columnName);
        self::assertTrue($metadata->primaryKey->autoIncrement);
        self::assertArrayHasKey('name', $metadata->columns);
        self::assertSame('name', $metadata->columns['name']->columnName);
        self::assertSame(ColumnType::String, $metadata->columns['name']->type);
    }

    #[Test]
    public function compileEntityWithTimestamps(): void
    {
        $metadata = $this->compiler->compile(CompilerTestTimestamped::class);

        self::assertTrue($metadata->hasTimestamps);
        self::assertSame('created_at', $metadata->createdAtColumn);
        self::assertSame('updated_at', $metadata->updatedAtColumn);
    }

    #[Test]
    public function compileEntityWithSoftDelete(): void
    {
        $metadata = $this->compiler->compile(CompilerTestSoftDeletable::class);

        self::assertTrue($metadata->hasSoftDelete);
        self::assertSame('deleted_at', $metadata->softDeleteColumn);
    }

    #[Test]
    public function compileEntityWithVersionColumn(): void
    {
        $metadata = $this->compiler->compile(CompilerTestVersioned::class);

        self::assertSame('version', $metadata->versionProperty);
        self::assertTrue($metadata->columns['version']->isVersion);
    }

    #[Test]
    public function compileEntityWithEncryptedColumn(): void
    {
        $metadata = $this->compiler->compile(CompilerTestEncrypted::class);

        self::assertContains('secret', $metadata->encryptedColumns);
        self::assertTrue($metadata->columns['secret']->encrypted);
    }

    #[Test]
    public function compileEntityWithRelation(): void
    {
        $metadata = $this->compiler->compile(CompilerTestWithRelation::class);

        self::assertArrayHasKey('posts', $metadata->relations);
        self::assertSame(RelationType::HasMany, $metadata->relations['posts']->type);
        self::assertSame(CompilerTestPost::class, $metadata->relations['posts']->targetEntity);
    }

    #[Test]
    public function compileThrowsForMissingTableAttribute(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageMatches('/missing.*#\[Table\]/');

        $this->compiler->compile(CompilerTestNoTable::class);
    }

    #[Test]
    public function compileThrowsForMissingIdAttribute(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageMatches('/no property.*#\[Id\]/');

        $this->compiler->compile(CompilerTestNoId::class);
    }

    #[Test]
    public function compileEntityWithSchemaAttribute(): void
    {
        $metadata = $this->compiler->compile(CompilerTestWithSchema::class);

        self::assertSame('billing', $metadata->schema);
        self::assertSame('orders', $metadata->tableName);
    }

    #[Test]
    public function compileEntityColumnDefaultsToPropertyName(): void
    {
        $metadata = $this->compiler->compile(CompilerTestUser::class);

        // Column name defaults to lowercase property name when not specified
        self::assertSame('name', $metadata->columns['name']->columnName);
    }

    #[Test]
    public function compileEntityWithExplicitColumnName(): void
    {
        $metadata = $this->compiler->compile(CompilerTestExplicitColumn::class);

        self::assertSame('email_address', $metadata->columns['email']->columnName);
    }

    #[Test]
    public function compileIdOnlyPropertyDefaultsToIntegerType(): void
    {
        $metadata = $this->compiler->compile(CompilerTestIdOnly::class);

        self::assertSame(ColumnType::Integer, $metadata->primaryKey->type);
        self::assertSame('id', $metadata->primaryKey->columnName);
    }
}

#[Table(name: 'users')]
class CompilerTestUser
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id;

    #[Column]
    public string $name = '';
}

#[Table(name: 'articles')]
#[Timestamps]
class CompilerTestTimestamped
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id;

    #[Column]
    public string $title = '';
}

#[Table(name: 'posts')]
#[SoftDelete]
class CompilerTestSoftDeletable
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id;

    #[Column]
    public string $title = '';
}

#[Table(name: 'records')]
class CompilerTestVersioned
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id;

    #[Column]
    public string $name = '';

    #[Version]
    #[Column(type: ColumnType::Integer)]
    public int $version = 0;
}

#[Table(name: 'secrets')]
class CompilerTestEncrypted
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id;

    #[Encrypted]
    #[Column]
    public ?string $secret = null;
}

#[Table(name: 'authors')]
class CompilerTestWithRelation
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id;

    #[Column]
    public string $name = '';

    /** @var list<CompilerTestPost> */
    #[Relation(type: RelationType::HasMany, target: CompilerTestPost::class, foreignKey: 'author_id')]
    public array $posts = [];
}

#[Table(name: 'posts')]
class CompilerTestPost
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id;

    #[Column(name: 'author_id', type: ColumnType::Integer)]
    public int $authorId;

    #[Column]
    public string $title = '';
}

class CompilerTestNoTable
{
    public int $id;
}

#[Table(name: 'broken')]
class CompilerTestNoId
{
    #[Column]
    public string $name = '';
}

#[Table(name: 'orders', schema: 'billing')]
class CompilerTestWithSchema
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id;

    #[Column]
    public string $status = '';
}

#[Table(name: 'contacts')]
class CompilerTestExplicitColumn
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id;

    #[Column(name: 'email_address', type: ColumnType::String)]
    public string $email = '';
}

#[Table(name: 'simple')]
class CompilerTestIdOnly
{
    #[Id]
    public int $id;
}
