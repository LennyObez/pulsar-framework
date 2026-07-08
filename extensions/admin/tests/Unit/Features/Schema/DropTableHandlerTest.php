<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\Schema;

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
use Pulsar\Extension\Admin\Features\Schema\DropTableHandler;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;

#[CoversClass(DropTableHandler::class)]
final class DropTableHandlerTest extends TestCase
{
    private SchemaManager $manager;
    private DatabaseIntrospector $introspector;
    private DropTableHandler $handler;

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

        $this->handler = new DropTableHandler(
            $this->manager,
            $this->introspector,
            $this->createStub(AuditLoggerInterface::class),
            $this->createStub(SchemaChangeLogStoreInterface::class),
            new AdminSchemaConfig(enabled: true),
        );
    }

    #[Test]
    public function dropsExistingTable(): void
    {
        $this->manager->createTable(new TableDefinition(
            name: 'temp_data',
            columns: [new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true)],
        ));

        $result = $this->handler->execute('temp_data', new MutationContext('admin', 'Cleanup temp table'));

        self::assertTrue($result['success']);
        $tables = array_map(fn($t) => $t->name, $this->introspector->tables());
        self::assertNotContains('temp_data', $tables);
    }

    #[Test]
    public function rejectsNonExistentTable(): void
    {
        $result = $this->handler->execute('nonexistent', new MutationContext('admin', 'Drop missing'));
        self::assertFalse($result['success']);
        self::assertStringContainsString('does not exist', $result['message']);
    }

    #[Test]
    public function rejectsDeniedPrefix(): void
    {
        $result = $this->handler->execute('pulsar_internal', new MutationContext('admin', 'Test denied'));
        self::assertFalse($result['success']);
        self::assertStringContainsString('reserved', $result['message']);
    }
}
