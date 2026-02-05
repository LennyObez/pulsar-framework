<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Hydration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Param;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;

#[CoversClass(EntityDehydrator::class)]
final class EntityDehydratorTest extends TestCase
{
    private MetadataRegistryInterface&Stub $metadataRegistry;

    protected function setUp(): void
    {
        $this->metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
    }

    #[Test]
    public function dehydrateForInsertReturnsColumnValues(): void
    {
        $metadata = $this->buildUserMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $dehydrator = new EntityDehydrator($this->metadataRegistry);

        $entity = new DehydrationTestUser();
        $entity->id = 0;
        $entity->name = 'Alice';
        $entity->email = 'alice@example.com';

        $values = $dehydrator->dehydrateForInsert($entity);

        // Auto-increment PK should be excluded from insert
        self::assertArrayNotHasKey('id', $values);
        self::assertSame('Alice', $values['name']);
        self::assertSame('alice@example.com', $values['email']);
    }

    #[Test]
    public function dehydrateForUpdateReturnsUpdatableColumns(): void
    {
        $metadata = $this->buildUserMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $dehydrator = new EntityDehydrator($this->metadataRegistry);

        $entity = new DehydrationTestUser();
        $entity->id = 42;
        $entity->name = 'Bob';
        $entity->email = 'bob@example.com';

        $values = $dehydrator->dehydrateForUpdate($entity);

        // PK and version are excluded from updatable columns
        self::assertArrayNotHasKey('id', $values);
        self::assertSame('Bob', $values['name']);
        self::assertSame('bob@example.com', $values['email']);
    }

    #[Test]
    public function extractIdReturnsPrimaryKeyValue(): void
    {
        $metadata = $this->buildUserMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $dehydrator = new EntityDehydrator($this->metadataRegistry);

        $entity = new DehydrationTestUser();
        $entity->id = 99;
        $entity->name = 'test';
        $entity->email = 'test@test.com';

        self::assertSame(99, $dehydrator->extractId($entity));
    }

    #[Test]
    public function extractVersionReturnsVersionValue(): void
    {
        $metadata = $this->buildVersionedMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $dehydrator = new EntityDehydrator($this->metadataRegistry);

        $entity = new DehydrationTestVersioned();
        $entity->id = 1;
        $entity->name = 'test';
        $entity->version = 5;

        self::assertSame(5, $dehydrator->extractVersion($entity));
    }

    #[Test]
    public function extractVersionReturnsNullWhenNoVersionProperty(): void
    {
        $metadata = $this->buildUserMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $dehydrator = new EntityDehydrator($this->metadataRegistry);

        $entity = new DehydrationTestUser();
        $entity->id = 1;
        $entity->name = 'test';
        $entity->email = 'test@test.com';

        self::assertNull($dehydrator->extractVersion($entity));
    }

    #[Test]
    public function dehydrateForInsertEncryptsEncryptedColumns(): void
    {
        $pk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
            autoIncrement: true,
        );

        $secretCol = new ColumnMetadata(
            propertyName: 'secret',
            columnName: 'secret',
            type: ColumnType::String,
            encrypted: true,
        );

        $metadata = new EntityMetadata(
            entityClass: DehydrationTestEncrypted::class,
            tableName: 'secrets',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'secret' => $secretCol],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: false,
            tenantColumn: null,
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: ['secret'],
        );

        $this->metadataRegistry->method('get')->willReturn($metadata);

        $encryptor = $this->createStub(ColumnEncryptorInterface::class);
        $encryptor->method('encrypt')->willReturn('encrypted_bytes');

        $dehydrator = new EntityDehydrator($this->metadataRegistry, $encryptor);

        $entity = new DehydrationTestEncrypted();
        $entity->id = 0;
        $entity->secret = 'my_secret';

        $values = $dehydrator->dehydrateForInsert($entity);

        self::assertInstanceOf(Param::class, $values['secret']);
        self::assertSame('encrypted_bytes', $values['secret']->bytes());
    }

    #[Test]
    public function dehydrateForInsertHandlesNullValues(): void
    {
        $metadata = $this->buildUserMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $dehydrator = new EntityDehydrator($this->metadataRegistry);

        $entity = new DehydrationTestUser();
        $entity->id = 0;
        $entity->name = null;
        $entity->email = null;

        $values = $dehydrator->dehydrateForInsert($entity);

        self::assertNull($values['name']);
        self::assertNull($values['email']);
    }

    private function buildUserMetadata(): EntityMetadata
    {
        $pk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
            autoIncrement: true,
        );

        $nameCol = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: ColumnType::String,
            nullable: true,
        );

        $emailCol = new ColumnMetadata(
            propertyName: 'email',
            columnName: 'email',
            type: ColumnType::String,
            nullable: true,
        );

        return new EntityMetadata(
            entityClass: DehydrationTestUser::class,
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol, 'email' => $emailCol],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: false,
            tenantColumn: null,
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: [],
        );
    }

    private function buildVersionedMetadata(): EntityMetadata
    {
        $pk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
            autoIncrement: true,
        );

        $nameCol = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: ColumnType::String,
        );

        $versionCol = new ColumnMetadata(
            propertyName: 'version',
            columnName: 'version',
            type: ColumnType::Integer,
            isVersion: true,
        );

        return new EntityMetadata(
            entityClass: DehydrationTestVersioned::class,
            tableName: 'articles',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $nameCol, 'version' => $versionCol],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: false,
            tenantColumn: null,
            isTenantShared: false,
            versionProperty: 'version',
            encryptedColumns: [],
        );
    }
}

/**
 * Stub entity for dehydration tests.
 */
class DehydrationTestUser
{
    public int $id;
    public ?string $name = null;
    public ?string $email = null;
}

/**
 * Stub entity with version column.
 */
class DehydrationTestVersioned
{
    public int $id;
    public string $name = '';
    public int $version = 0;
}

/**
 * Stub entity with encrypted column.
 */
class DehydrationTestEncrypted
{
    public int $id;
    public ?string $secret = null;
}
