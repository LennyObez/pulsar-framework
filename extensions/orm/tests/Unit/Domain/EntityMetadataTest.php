<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\PostEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity;

final class EntityMetadataTest extends TestCase
{
    private function createMetadata(): EntityMetadata
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::BigInt, isPrimaryKey: true, autoIncrement: true, insertable: false);
        $name = new ColumnMetadata('name', 'name', ColumnType::String);
        $email = new ColumnMetadata('email', 'email_address', ColumnType::String);
        $version = new ColumnMetadata('version', 'version', ColumnType::Integer, isVersion: true, updatable: false);
        $readOnly = new ColumnMetadata('audit', 'audit_hash', ColumnType::String, insertable: true, updatable: false);

        return new EntityMetadata(
            entityClass: UserEntity::class,
            tableName: 'users',
            schema: 'public',
            primaryKey: $pk,
            columns: ['id' => $pk, 'name' => $name, 'email' => $email, 'version' => $version, 'audit' => $readOnly],
            relations: [],
            hasTimestamps: true,
            createdAtColumn: 'created_at',
            updatedAtColumn: 'updated_at',
            hasSoftDelete: true,
            softDeleteColumn: 'deleted_at',
            isTenantScoped: true,
            tenantColumn: 'tenant_id',
            isTenantShared: false,
            versionProperty: 'version',
            encryptedColumns: [],
        );
    }

    #[Test]
    public function columnByPropertyReturnsMatch(): void
    {
        $meta = $this->createMetadata();

        $col = $meta->columnByProperty('email');
        self::assertNotNull($col);
        self::assertSame('email_address', $col->columnName);
    }

    #[Test]
    public function columnByPropertyReturnsNullForMissing(): void
    {
        $meta = $this->createMetadata();

        self::assertNull($meta->columnByProperty('nonexistent'));
    }

    #[Test]
    public function columnByNameReturnsMatch(): void
    {
        $meta = $this->createMetadata();

        $col = $meta->columnByName('email_address');
        self::assertNotNull($col);
        self::assertSame('email', $col->propertyName);
    }

    #[Test]
    public function columnByNameReturnsNullForMissing(): void
    {
        $meta = $this->createMetadata();

        self::assertNull($meta->columnByName('not_a_column'));
    }

    #[Test]
    public function insertableColumnsExcludeAutoIncrementPk(): void
    {
        $meta = $this->createMetadata();

        $insertable = $meta->insertableColumns();

        self::assertArrayNotHasKey('id', $insertable);
        self::assertArrayHasKey('name', $insertable);
        self::assertArrayHasKey('email', $insertable);
        self::assertArrayHasKey('audit', $insertable);
    }

    #[Test]
    public function updatableColumnsExcludePkAndVersion(): void
    {
        $meta = $this->createMetadata();

        $updatable = $meta->updatableColumns();

        self::assertArrayNotHasKey('id', $updatable);
        self::assertArrayNotHasKey('version', $updatable);
        self::assertArrayHasKey('name', $updatable);
        self::assertArrayHasKey('email', $updatable);
        self::assertArrayNotHasKey('audit', $updatable);
    }

    #[Test]
    public function qualifiedTableNameWithSchema(): void
    {
        $meta = $this->createMetadata();

        self::assertSame('public.users', $meta->qualifiedTableName());
    }

    #[Test]
    public function qualifiedTableNameWithoutSchema(): void
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::BigInt, isPrimaryKey: true);
        $meta = new EntityMetadata(
            entityClass: PostEntity::class,
            tableName: 'posts',
            schema: null,
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

        self::assertSame('posts', $meta->qualifiedTableName());
    }
}
