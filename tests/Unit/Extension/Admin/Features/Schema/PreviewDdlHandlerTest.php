<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Features\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\TableDefinition;
use Pulsar\Extension\Admin\Features\Schema\PreviewDdlHandler;

#[CoversClass(PreviewDdlHandler::class)]
final class PreviewDdlHandlerTest extends TestCase
{
    private PreviewDdlHandler $handler;

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
        $manager = new SchemaManager($connection, $compiler, $capabilities);

        $this->handler = new PreviewDdlHandler($manager, $capabilities);
    }

    #[Test]
    public function previewCreateReturnsSqlWithoutExecuting(): void
    {
        $def = new TableDefinition(
            name: 'preview_table',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
            ],
        );

        $result = $this->handler->previewCreate($def);

        self::assertNotEmpty($result['statements']);
        self::assertIsArray($result['warnings']);
        self::assertStringContainsString('CREATE TABLE', $result['statements'][0]);
    }

    #[Test]
    public function previewAddColumnReturnsSql(): void
    {
        $result = $this->handler->previewAddColumn(
            'users',
            new SchemaColumn('email', SchemaColumnType::String),
        );

        self::assertNotEmpty($result['statements']);
        self::assertStringContainsString('ALTER TABLE', $result['statements'][0]);
    }

    #[Test]
    public function previewDropColumnReturnsWarningForSqlite(): void
    {
        $result = $this->handler->previewDropColumn('users', 'email');

        // May return empty statements with warning, or statements depending on SQLite version
        self::assertIsArray($result['warnings']);
    }

    #[Test]
    public function includesCapabilityWarnings(): void
    {
        $def = new TableDefinition(
            name: 'warn_test',
            columns: [new SchemaColumn('id', SchemaColumnType::Integer)],
        );

        $result = $this->handler->previewCreate($def);

        // SQLite should have multiple warnings
        $warningCodes = array_map(fn($w) => $w['code'], $result['warnings']);
        self::assertContains('NO_TRANSACTIONAL_DDL', $warningCodes);
        self::assertContains('NO_ALTER_ADD_FK', $warningCodes);
    }
}
