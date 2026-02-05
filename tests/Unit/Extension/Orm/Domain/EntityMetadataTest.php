<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;

#[CoversClass(EntityMetadata::class)]
final class EntityMetadataTest extends TestCase
{
    private EntityMetadata $metadata;

    /**
     * Cast a string to class-string for test purposes.
     *
     * @return class-string
     */
    private static function fakeClass(string $name): string
    {
        /** @var class-string */
        return $name;
    }

    protected function setUp(): void
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

        $emailCol = new ColumnMetadata(
            propertyName: 'email',
            columnName: 'email_address',
            type: ColumnType::String,
        );

        $readOnlyCol = new ColumnMetadata(
            propertyName: 'createdBy',
            columnName: 'created_by',
            type: ColumnType::String,
            insertable: true,
            updatable: false,
        );

        $versionCol = new ColumnMetadata(
            propertyName: 'version',
            columnName: 'version',
            type: ColumnType::Integer,
            isVersion: true,
        );

        $this->metadata = new EntityMetadata(
            entityClass: self::fakeClass('App\\Entity\\User'),
            tableName: 'users',
            schema: null,
            primaryKey: $pk,
            columns: [
                'id' => $pk,
                'name' => $nameCol,
                'email' => $emailCol,
                'createdBy' => $readOnlyCol,
                'version' => $versionCol,
            ],
            relations: [],
            hasTimestamps: true,
            createdAtColumn: 'created_at',
            updatedAtColumn: 'updated_at',
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: false,
            tenantColumn: null,
            isTenantShared: false,
            versionProperty: 'version',
            encryptedColumns: [],
        );
    }

    #[Test]
    public function columnByPropertyReturnsMatchingColumn(): void
    {
        $col = $this->metadata->columnByProperty('name');

        self::assertNotNull($col);
        self::assertSame('name', $col->columnName);
    }

    #[Test]
    public function columnByPropertyReturnsNullForMissing(): void
    {
        self::assertNull($this->metadata->columnByProperty('nonexistent'));
    }

    #[Test]
    public function columnByNameReturnsMatchingColumn(): void
    {
        $col = $this->metadata->columnByName('email_address');

        self::assertNotNull($col);
        self::assertSame('email', $col->propertyName);
    }

    #[Test]
    public function columnByNameReturnsNullForMissing(): void
    {
        self::assertNull($this->metadata->columnByName('nonexistent_column'));
    }

    #[Test]
    public function insertableColumnsExcludesAutoIncrementPk(): void
    {
        $insertable = $this->metadata->insertableColumns();

        self::assertArrayNotHasKey('id', $insertable);
        self::assertArrayHasKey('name', $insertable);
        self::assertArrayHasKey('email', $insertable);
        self::assertArrayHasKey('createdBy', $insertable);
        self::assertArrayHasKey('version', $insertable);
    }

    #[Test]
    public function updatableColumnsExcludesPkAndVersion(): void
    {
        $updatable = $this->metadata->updatableColumns();

        self::assertArrayNotHasKey('id', $updatable);
        self::assertArrayNotHasKey('version', $updatable);
        self::assertArrayNotHasKey('createdBy', $updatable);
        self::assertArrayHasKey('name', $updatable);
        self::assertArrayHasKey('email', $updatable);
    }

    #[Test]
    public function qualifiedTableNameWithoutSchema(): void
    {
        self::assertSame('users', $this->metadata->qualifiedTableName());
    }

    #[Test]
    public function qualifiedTableNameWithSchema(): void
    {
        $pk = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            isPrimaryKey: true,
        );

        $metadata = new EntityMetadata(
            entityClass: self::fakeClass('App\\Entity\\Order'),
            tableName: 'orders',
            schema: 'billing',
            primaryKey: $pk,
            columns: ['id' => $pk],
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

        self::assertSame('billing.orders', $metadata->qualifiedTableName());
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        self::assertSame('App\\Entity\\User', $this->metadata->entityClass);
        self::assertSame('users', $this->metadata->tableName);
        self::assertNull($this->metadata->schema);
        self::assertTrue($this->metadata->hasTimestamps);
        self::assertSame('created_at', $this->metadata->createdAtColumn);
        self::assertSame('updated_at', $this->metadata->updatedAtColumn);
        self::assertFalse($this->metadata->hasSoftDelete);
        self::assertNull($this->metadata->softDeleteColumn);
        self::assertFalse($this->metadata->isTenantScoped);
        self::assertNull($this->metadata->tenantColumn);
        self::assertFalse($this->metadata->isTenantShared);
        self::assertSame('version', $this->metadata->versionProperty);
        self::assertSame([], $this->metadata->encryptedColumns);
        self::assertCount(5, $this->metadata->columns);
        self::assertCount(0, $this->metadata->relations);
    }
}
