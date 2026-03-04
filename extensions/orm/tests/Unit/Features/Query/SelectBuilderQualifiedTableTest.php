<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use stdClass;

final class SelectBuilderQualifiedTableTest extends TestCase
{
    private function createMetadata(?string $schema): EntityMetadata
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

        return new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'accounts',
            schema: $schema,
            primaryKey: $pkCol,
            columns: ['id' => $pkCol],
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

    #[Test]
    public function forEntityUsesQualifiedTableNameWithSchema(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);

        $metadata = $this->createMetadata('billing');
        $hydrator = $this->createStub(EntityHydratorInterface::class);

        $builder = new SelectBuilder($connection);
        $builder->forEntity(stdClass::class, $metadata, $hydrator);

        $sql = $builder->toSql();
        // The quoter renders schema.table as "billing"."accounts"
        self::assertStringContainsString('"billing"', $sql['sql']);
        self::assertStringContainsString('"accounts"', $sql['sql']);
    }

    #[Test]
    public function forEntityUsesPlainTableNameWithoutSchema(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        $metadata = $this->createMetadata(null);
        $hydrator = $this->createStub(EntityHydratorInterface::class);

        $builder = new SelectBuilder($connection);
        $builder->forEntity(stdClass::class, $metadata, $hydrator);

        $sql = $builder->toSql();
        // MySQL uses backtick quoting: `accounts`
        self::assertStringContainsString('accounts', $sql['sql']);
        // No schema prefix in the SQL
        self::assertStringNotContainsString('billing', $sql['sql']);
    }
}
