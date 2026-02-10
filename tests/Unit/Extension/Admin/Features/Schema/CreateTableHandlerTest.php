<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Features\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
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
use Pulsar\Extension\Admin\Features\Schema\CreateTableHandler;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;

#[CoversClass(CreateTableHandler::class)]
final class CreateTableHandlerTest extends TestCase
{
    private PdoConnection $connection;
    private CreateTableHandler $handler;
    private AuditLoggerInterface&MockObject $auditLogger;
    private SchemaChangeLogStoreInterface&MockObject $changeLog;

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
        $introspector = new DatabaseIntrospector($this->connection);

        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->changeLog = $this->createMock(SchemaChangeLogStoreInterface::class);

        $this->handler = new CreateTableHandler(
            $manager,
            $introspector,
            $this->auditLogger,
            $this->changeLog,
            new AdminSchemaConfig(enabled: true),
        );
    }

    #[Test]
    public function createsTableSuccessfully(): void
    {
        $this->auditLogger->expects($this->once())->method('log');
        $this->changeLog->expects($this->once())->method('record');

        $def = new TableDefinition(
            name: 'products',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('name', SchemaColumnType::String),
            ],
        );

        $result = $this->handler->execute($def, new MutationContext('admin', 'Initial setup'));

        self::assertTrue($result['success']);
        self::assertStringContainsString('created', $result['message']);
        self::assertArrayHasKey('sql', $result);
    }

    #[Test]
    public function rejectsInvalidName(): void
    {
        $def = new TableDefinition(
            name: 'select',
            columns: [new SchemaColumn('id', SchemaColumnType::Integer)],
        );

        $this->expectException(\Pulsar\Database\Schema\SchemaException::class);
        $this->handler->execute($def, new MutationContext('admin', 'Test create'));
    }

    #[Test]
    public function rejectsDeniedPrefix(): void
    {
        $def = new TableDefinition(
            name: 'admin_secret',
            columns: [new SchemaColumn('id', SchemaColumnType::Integer)],
        );

        $result = $this->handler->execute($def, new MutationContext('admin', 'Test create'));
        self::assertFalse($result['success']);
        self::assertStringContainsString('reserved', $result['message']);
    }

    #[Test]
    public function rejectsDuplicateTable(): void
    {
        $def = new TableDefinition(
            name: 'items',
            columns: [new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true)],
        );

        // Create it first
        $this->handler->execute($def, new MutationContext('admin', 'First create'));

        // Try again
        $result = $this->handler->execute($def, new MutationContext('admin', 'Duplicate create'));
        self::assertFalse($result['success']);
        self::assertStringContainsString('already exists', $result['message']);
    }
}
