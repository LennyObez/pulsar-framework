<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\PdoConnection;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\TableDefinition;

#[CoversClass(SchemaManager::class)]
final class SchemaManagerTest extends TestCase
{
    private PdoConnection $connection;
    private SchemaManager $manager;
    private DatabaseIntrospector $introspector;

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
        $this->manager = new SchemaManager($this->connection, $compiler, $capabilities);
        $this->introspector = new DatabaseIntrospector($this->connection);
    }

    #[Test]
    public function createTableExecutesDdl(): void
    {
        $def = new TableDefinition(
            name: 'users',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('name', SchemaColumnType::String, length: 100),
            ],
        );

        $this->manager->createTable($def);

        $tables = $this->introspector->tables();
        $tableNames = array_map(fn($t) => $t->name, $tables);
        self::assertContains('users', $tableNames);
    }

    #[Test]
    public function addColumnUpdatesSchema(): void
    {
        $this->createUsersTable();

        $this->manager->addColumn('users', new SchemaColumn('email', SchemaColumnType::String, nullable: true));

        $columns = $this->introspector->columns('users');
        $colNames = array_map(fn($c) => $c->name, $columns);
        self::assertContains('email', $colNames);
    }

    #[Test]
    public function dropTableRemovesTable(): void
    {
        $this->createUsersTable();

        $this->manager->dropTable('users');

        $tables = $this->introspector->tables();
        $tableNames = array_map(fn($t) => $t->name, $tables);
        self::assertNotContains('users', $tableNames);
    }

    #[Test]
    public function previewReturnsWithoutExecuting(): void
    {
        $def = new TableDefinition(
            name: 'preview_test',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
            ],
        );

        $stmts = $this->manager->previewCreateTable($def);

        self::assertNotEmpty($stmts);

        $tables = $this->introspector->tables();
        $tableNames = array_map(fn($t) => $t->name, $tables);
        self::assertNotContains('preview_test', $tableNames);
    }

    #[Test]
    public function capabilitiesExposed(): void
    {
        $cap = $this->manager->capabilities();
        self::assertInstanceOf(SchemaCapabilities::class, $cap);
    }

    #[Test]
    public function renameTableWorks(): void
    {
        $this->createUsersTable();

        $this->manager->renameTable('users', 'members');

        $tables = $this->introspector->tables();
        $tableNames = array_map(fn($t) => $t->name, $tables);
        self::assertNotContains('users', $tableNames);
        self::assertContains('members', $tableNames);
    }

    private function createUsersTable(): void
    {
        $this->manager->createTable(new TableDefinition(
            name: 'users',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('name', SchemaColumnType::String, length: 100),
            ],
        ));
    }
}
