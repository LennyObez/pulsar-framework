<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Exception\EncryptedColumnQueryException;
use Pulsar\Extension\Orm\Features\Encryption\EncryptedColumnGuard;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use stdClass;

final class SelectBuilderEncryptedGuardTest extends TestCase
{
    private function createMetadataWithEncryptedColumn(bool $hasBlindIndex): EntityMetadata
    {
        $pkCol = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            nullable: false,
            isPrimaryKey: true,
            autoIncrement: true,
            isVersion: false,
            encrypted: false,
            blindIndexColumn: null,
            blindIndexHashLength: null,
            insertable: true,
            updatable: false,
            casterClass: null,
        );

        $ssnCol = new ColumnMetadata(
            propertyName: 'ssn',
            columnName: 'ssn',
            type: ColumnType::String,
            nullable: false,
            isPrimaryKey: false,
            autoIncrement: false,
            isVersion: false,
            encrypted: true,
            blindIndexColumn: $hasBlindIndex ? 'ssn_idx' : null,
            blindIndexHashLength: $hasBlindIndex ? 32 : null,
            insertable: true,
            updatable: true,
            casterClass: null,
        );

        $nameCol = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: ColumnType::String,
            nullable: false,
            isPrimaryKey: false,
            autoIncrement: false,
            isVersion: false,
            encrypted: false,
            blindIndexColumn: null,
            blindIndexHashLength: null,
            insertable: true,
            updatable: true,
            casterClass: null,
        );

        return new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'persons',
            schema: null,
            primaryKey: $pkCol,
            columns: ['id' => $pkCol, 'ssn' => $ssnCol, 'name' => $nameCol],
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
            encryptedColumns: ['ssn'],
        );
    }

    private function createBuilder(EntityMetadata $metadata): SelectBuilder
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $registry = $this->createStub(MetadataRegistryInterface::class);
        $registry->method('get')->willReturn($metadata);

        $guard = new EncryptedColumnGuard($registry);

        $hydrator = $this->createStub(EntityHydratorInterface::class);

        $builder = new SelectBuilder($connection);
        $builder->withEncryptedColumnGuard($guard);
        $builder->forEntity(stdClass::class, $metadata, $hydrator);

        return $builder;
    }

    #[Test]
    public function whereOnEncryptedColumnWithoutBlindIndexThrows(): void
    {
        $metadata = $this->createMetadataWithEncryptedColumn(false);
        $builder = $this->createBuilder($metadata);

        $this->expectException(EncryptedColumnQueryException::class);
        $builder->where('ssn', '123-45-6789');
    }

    #[Test]
    public function whereOpOnEncryptedColumnWithoutBlindIndexThrows(): void
    {
        $metadata = $this->createMetadataWithEncryptedColumn(false);
        $builder = $this->createBuilder($metadata);

        $this->expectException(EncryptedColumnQueryException::class);
        $builder->whereOp('ssn', '=', '123');
    }

    #[Test]
    public function whereInOnEncryptedColumnWithoutBlindIndexThrows(): void
    {
        $metadata = $this->createMetadataWithEncryptedColumn(false);
        $builder = $this->createBuilder($metadata);

        $this->expectException(EncryptedColumnQueryException::class);
        $builder->whereIn('ssn', ['123', '456']);
    }

    #[Test]
    public function orderByOnEncryptedColumnThrows(): void
    {
        $metadata = $this->createMetadataWithEncryptedColumn(true);
        $builder = $this->createBuilder($metadata);

        $this->expectException(EncryptedColumnQueryException::class);
        $builder->orderBy('ssn');
    }

    #[Test]
    public function whereOnEncryptedColumnWithBlindIndexSucceeds(): void
    {
        $metadata = $this->createMetadataWithEncryptedColumn(true);
        $builder = $this->createBuilder($metadata);

        // Should not throw; blind index is configured
        $builder->where('ssn', '123');

        $sql = $builder->toSql();
        self::assertStringContainsString('ssn', $sql['sql']);
    }

    #[Test]
    public function whereOnNonEncryptedColumnSucceeds(): void
    {
        $metadata = $this->createMetadataWithEncryptedColumn(false);
        $builder = $this->createBuilder($metadata);

        $builder->where('name', 'Alice');

        $sql = $builder->toSql();
        self::assertStringContainsString('name', $sql['sql']);
    }

    #[Test]
    public function orderByNonEncryptedColumnSucceeds(): void
    {
        $metadata = $this->createMetadataWithEncryptedColumn(false);
        $builder = $this->createBuilder($metadata);

        $builder->orderBy('name');

        $sql = $builder->toSql();
        self::assertStringContainsString('name', $sql['sql']);
    }

    #[Test]
    public function withoutGuardNoExceptionOnEncryptedWhere(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $metadata = $this->createMetadataWithEncryptedColumn(false);
        $hydrator = $this->createStub(EntityHydratorInterface::class);

        // No guard set: should not throw
        $builder = new SelectBuilder($connection);
        $builder->forEntity(stdClass::class, $metadata, $hydrator);
        $builder->where('ssn', '123');

        $sql = $builder->toSql();
        self::assertStringContainsString('ssn', $sql['sql']);
    }
}
