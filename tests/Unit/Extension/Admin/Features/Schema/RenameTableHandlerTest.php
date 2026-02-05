<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Features\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\Driver;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\PdoConnection;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\TableDefinition;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Features\Schema\RenameTableHandler;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;

#[CoversClass(RenameTableHandler::class)]
final class RenameTableHandlerTest extends TestCase
{
    private SchemaManager $manager;
    private DatabaseIntrospector $introspector;
    private RenameTableHandler $handler;

    protected function setUp(): void
    {
        $connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $capabilities = new SchemaCapabilities(Driver::SQLite, $connection);
        $compiler = new DdlCompiler(Driver::SQLite, $capabilities);
        $this->manager = new SchemaManager($connection, $compiler, $capabilities);
        $this->introspector = new DatabaseIntrospector($connection);

        $this->handler = new RenameTableHandler(
            $this->manager,
            $this->introspector,
            $this->createStub(AuditLoggerInterface::class),
            $this->createStub(SchemaChangeLogStoreInterface::class),
            new AdminSchemaConfig(enabled: true),
        );
    }

    #[Test]
    public function renamesExistingTable(): void
    {
        $this->manager->createTable(new TableDefinition(
            name: 'old_name',
            columns: [new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true)],
        ));

        $result = $this->handler->execute('old_name', 'new_name', new MutationContext('admin', 'Rename table'));

        self::assertTrue($result['success']);
        $tables = array_map(fn($t) => $t->name, $this->introspector->tables());
        self::assertNotContains('old_name', $tables);
        self::assertContains('new_name', $tables);
    }

    #[Test]
    public function rejectsNonExistentSource(): void
    {
        $result = $this->handler->execute('ghost', 'new_ghost', new MutationContext('admin', 'Rename missing'));
        self::assertFalse($result['success']);
        self::assertStringContainsString('does not exist', $result['message']);
    }

    #[Test]
    public function rejectsExistingTarget(): void
    {
        $this->manager->createTable(new TableDefinition(
            name: 'first_table',
            columns: [new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true)],
        ));
        $this->manager->createTable(new TableDefinition(
            name: 'second_table',
            columns: [new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true)],
        ));

        $result = $this->handler->execute('first_table', 'second_table', new MutationContext('admin', 'Rename conflict'));
        self::assertFalse($result['success']);
        self::assertStringContainsString('already exists', $result['message']);
    }
}
