<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Hydration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Hydration\EntityHydrator;

#[CoversClass(EntityHydrator::class)]
final class EntityHydratorTest extends TestCase
{
    private MetadataRegistryInterface&Stub $metadataRegistry;

    protected function setUp(): void
    {
        $this->metadataRegistry = $this->createStub(MetadataRegistryInterface::class);
    }

    #[Test]
    public function hydrateCreatesEntityFromRow(): void
    {
        $metadata = $this->buildUserMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $hydrator = new EntityHydrator($this->metadataRegistry);
        $row = new Row(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com']);

        /** @var HydrationTestUser $entity */
        $entity = $hydrator->hydrate(HydrationTestUser::class, $row);

        self::assertInstanceOf(HydrationTestUser::class, $entity);
        self::assertSame(1, $entity->id);
        self::assertSame('Alice', $entity->name);
        self::assertSame('alice@example.com', $entity->email);
    }

    #[Test]
    public function hydrateSkipsMissingColumns(): void
    {
        $metadata = $this->buildUserMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $hydrator = new EntityHydrator($this->metadataRegistry);
        $row = new Row(['id' => 2, 'name' => 'Bob']);

        /** @var HydrationTestUser $entity */
        $entity = $hydrator->hydrate(HydrationTestUser::class, $row);

        self::assertSame(2, $entity->id);
        self::assertSame('Bob', $entity->name);
    }

    #[Test]
    public function hydrateHandlesNullValues(): void
    {
        $metadata = $this->buildUserMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $hydrator = new EntityHydrator($this->metadataRegistry);
        $row = new Row(['id' => 3, 'name' => null, 'email' => null]);

        /** @var HydrationTestUser $entity */
        $entity = $hydrator->hydrate(HydrationTestUser::class, $row);

        self::assertSame(3, $entity->id);
        self::assertNull($entity->name);
        self::assertNull($entity->email);
    }

    #[Test]
    public function hydrateDecryptsEncryptedColumns(): void
    {
        $encryptedCol = new ColumnMetadata(
            propertyName: 'secret',
            columnName: 'secret',
            type: ColumnType::String,
            encrypted: true,
        );

        $pk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );

        $metadata = new EntityMetadata(
            entityClass: HydrationTestEncrypted::class,
            tableName: 'secrets',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'secret' => $encryptedCol],
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
        $encryptor->method('decrypt')->willReturn('decrypted_value');

        $hydrator = new EntityHydrator($this->metadataRegistry, $encryptor);
        $row = new Row(['id' => 1, 'secret' => 'encrypted_bytes']);

        /** @var HydrationTestEncrypted $entity */
        $entity = $hydrator->hydrate(HydrationTestEncrypted::class, $row);

        self::assertSame('decrypted_value', $entity->secret);
    }

    #[Test]
    public function hydrateAllProcessesMultipleRows(): void
    {
        $metadata = $this->buildUserMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $hydrator = new EntityHydrator($this->metadataRegistry);

        $rows = [
            new Row(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com']),
            new Row(['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com']),
        ];

        $entities = $hydrator->hydrateAll(HydrationTestUser::class, $rows);

        self::assertCount(2, $entities);
        self::assertSame('Alice', $entities[0]->name);
        self::assertSame('Bob', $entities[1]->name);
    }

    #[Test]
    public function hydrateAllReturnsEmptyForEmptyInput(): void
    {
        $hydrator = new EntityHydrator($this->metadataRegistry);
        $entities = $hydrator->hydrateAll(HydrationTestUser::class, []);

        self::assertSame([], $entities);
    }

    #[Test]
    public function hydrateCastsTypesViaTypeCaster(): void
    {
        $metadata = $this->buildUserMetadata();
        $this->metadataRegistry->method('get')->willReturn($metadata);

        $hydrator = new EntityHydrator($this->metadataRegistry);
        // Pass string '42' for an integer column — TypeCaster should cast it
        $row = new Row(['id' => '42', 'name' => 'Charlie', 'email' => 'c@test.com']);

        /** @var HydrationTestUser $entity */
        $entity = $hydrator->hydrate(HydrationTestUser::class, $row);

        self::assertSame(42, $entity->id);
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
            entityClass: HydrationTestUser::class,
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
}

/**
 * Stub entity for hydration tests.
 */
class HydrationTestUser
{
    public int $id;
    public ?string $name = null;
    public ?string $email = null;
}

/**
 * Stub entity for encrypted column tests.
 */
class HydrationTestEncrypted
{
    public int $id;
    public ?string $secret = null;
}
