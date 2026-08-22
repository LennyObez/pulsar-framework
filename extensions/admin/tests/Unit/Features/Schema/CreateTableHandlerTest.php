<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\Schema;

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
use Pulsar\Database\Schema\SchemaException;
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

        $this->handler = new CreateTableHandler(
            $this->manager,
            $this->introspector,
            $this->createStub(AuditLoggerInterface::class),
            $this->createStub(SchemaChangeLogStoreInterface::class),
            new AdminSchemaConfig(enabled: true),
        );
    }

    #[Test]
    public function createsTableSuccessfully(): void
    {
        /** @var AuditLoggerInterface&MockObject $auditLogger */
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->once())->method('log');

        /** @var SchemaChangeLogStoreInterface&MockObject $changeLog */
        $changeLog = $this->createMock(SchemaChangeLogStoreInterface::class);
        $changeLog->expects($this->once())->method('record');

        $capabilities = new SchemaCapabilities(Driver::SQLite, $this->connection);
        $compiler = new DdlCompiler(Driver::SQLite, $capabilities);
        $manager = new SchemaManager($this->connection, $compiler, $capabilities);
        $introspector = new DatabaseIntrospector($this->connection);

        $handler = new CreateTableHandler(
            $manager,
            $introspector,
            $auditLogger,
            $changeLog,
            new AdminSchemaConfig(enabled: true),
        );

        $def = new TableDefinition(
            name: 'products',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('name', SchemaColumnType::String),
            ],
        );

        $result = $handler->execute($def, new MutationContext('admin', 'Initial setup'));

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

        $this->expectException(SchemaException::class);
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
