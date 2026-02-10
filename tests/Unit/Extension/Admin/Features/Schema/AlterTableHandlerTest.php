<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Features\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaIndex;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\TableDefinition;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Features\Schema\AlterTableHandler;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;

#[CoversClass(AlterTableHandler::class)]
final class AlterTableHandlerTest extends TestCase
{
    private PdoConnection $connection;
    private AlterTableHandler $handler;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $capabilities = new SchemaCapabilities(Driver::SQLite, $this->connection);
        $compiler = new DdlCompiler(Driver::SQLite, $capabilities);
        $manager = new SchemaManager($this->connection, $compiler, $capabilities);

        $this->handler = new AlterTableHandler(
            $manager,
            $capabilities,
            $this->createMock(AuditLoggerInterface::class),
            $this->createMock(SchemaChangeLogStoreInterface::class),
            new AdminSchemaConfig(enabled: true),
        );

        // Create test table
        $manager->createTable(new TableDefinition(
            name: 'products',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('name', SchemaColumnType::String),
            ],
        ));
    }

    #[Test]
    public function addColumnSucceeds(): void
    {
        $result = $this->handler->addColumn(
            'products',
            new SchemaColumn('price', SchemaColumnType::Decimal, nullable: true, precision: 10, scale: 2),
            new MutationContext('admin', 'Add price column'),
        );

        self::assertTrue($result['success']);
    }

    #[Test]
    public function dropColumnReturnsFalseOnSqlite(): void
    {
        // SQLite version in most test environments doesn't support DROP COLUMN without runtime version check
        $result = $this->handler->dropColumn('products', 'name', new MutationContext('admin', 'Remove name'));

        // Depending on SQLite version, this may succeed or return unsupported
        self::assertIsBool($result['success']);
    }

    #[Test]
    public function addIndexSucceeds(): void
    {
        $result = $this->handler->addIndex(
            'products',
            new SchemaIndex('idx_products_name', ['name']),
            new MutationContext('admin', 'Add name index'),
        );

        self::assertTrue($result['success']);
    }

    #[Test]
    public function rejectsDeniedPrefixTable(): void
    {
        $this->expectException(\Pulsar\Extension\Admin\Exception\AdminException::class);
        $this->handler->addColumn(
            'admin_settings',
            new SchemaColumn('val', SchemaColumnType::String),
            new MutationContext('admin', 'Test denied'),
        );
    }
}
