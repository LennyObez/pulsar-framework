<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaDefaultExpression;
use Pulsar\Database\Schema\SchemaException;
use Pulsar\Database\Schema\SchemaForeignKey;
use Pulsar\Database\Schema\SchemaIndex;
use Pulsar\Database\Schema\SchemaReferentialAction;
use Pulsar\Database\Schema\TableDefinition;

#[CoversClass(DdlCompiler::class)]
final class DdlCompilerTest extends TestCase
{
    #[Test]
    public function createTableSqlite(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'users',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('name', SchemaColumnType::String, length: 100),
                new SchemaColumn('email', SchemaColumnType::String, unique: true),
            ],
            indexes: [
                new SchemaIndex('idx_name', ['name']),
            ],
        );

        $stmts = $compiler->compileCreate($def);

        self::assertCount(2, $stmts); // CREATE TABLE + CREATE INDEX
        self::assertStringContainsString('CREATE TABLE "users"', $stmts[0]);
        self::assertStringContainsString('"id" INTEGER PRIMARY KEY AUTOINCREMENT', $stmts[0]);
        self::assertStringContainsString('"name" VARCHAR(100)', $stmts[0]);
        self::assertStringContainsString('UNIQUE', $stmts[0]);
        self::assertStringContainsString('CREATE INDEX "idx_name" ON "users" ("name")', $stmts[1]);
    }

    #[Test]
    public function createTableMysql(): void
    {
        $compiler = $this->compiler(Driver::MySQL);
        $def = new TableDefinition(
            name: 'users',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('active', SchemaColumnType::Boolean),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertCount(1, $stmts);
        self::assertStringContainsString('`id` INTEGER', $stmts[0]);
        self::assertStringContainsString('AUTO_INCREMENT', $stmts[0]);
        self::assertStringContainsString('`active` TINYINT(1)', $stmts[0]);
    }

    #[Test]
    public function createTablePgsql(): void
    {
        $compiler = $this->compiler(Driver::PostgreSQL);
        $def = new TableDefinition(
            name: 'users',
            columns: [
                new SchemaColumn('id', SchemaColumnType::BigInt, primaryKey: true, autoIncrement: true),
                new SchemaColumn('active', SchemaColumnType::Boolean),
                new SchemaColumn('data', SchemaColumnType::Json, nullable: true),
                new SchemaColumn('created_at', SchemaColumnType::DateTime, defaultExpression: SchemaDefaultExpression::CurrentTimestamp),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertCount(1, $stmts);
        self::assertStringContainsString('"id" BIGSERIAL', $stmts[0]);
        self::assertStringContainsString('"active" BOOLEAN', $stmts[0]);
        self::assertStringContainsString('"data" JSONB', $stmts[0]);
        self::assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $stmts[0]);
    }

    #[Test]
    public function createWithEnumMysql(): void
    {
        $compiler = $this->compiler(Driver::MySQL);
        $def = new TableDefinition(
            name: 'tickets',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('status', SchemaColumnType::Enum, enumValues: ['open', 'closed', 'pending']),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString("ENUM('open', 'closed', 'pending')", $stmts[0]);
    }

    #[Test]
    public function createWithEnumPgsqlSqlite(): void
    {
        $compiler = $this->compiler(Driver::PostgreSQL);
        $def = new TableDefinition(
            name: 'tickets',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('status', SchemaColumnType::Enum, enumValues: ['open', 'closed']),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString("CHECK (\"status\" IN ('open', 'closed'))", $stmts[0]);
    }

    #[Test]
    public function createWithForeignKeys(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'orders',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('user_id', SchemaColumnType::Integer),
            ],
            foreignKeys: [
                new SchemaForeignKey(
                    name: 'fk_orders_user',
                    columns: ['user_id'],
                    referencedTable: 'users',
                    referencedColumns: ['id'],
                    onDelete: SchemaReferentialAction::Cascade,
                    onUpdate: SchemaReferentialAction::Restrict,
                ),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('CONSTRAINT "fk_orders_user" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE ON UPDATE RESTRICT', $stmts[0]);
    }

    #[Test]
    public function createWithCompositePrimaryKey(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'pivot',
            columns: [
                new SchemaColumn('user_id', SchemaColumnType::Integer, primaryKey: true),
                new SchemaColumn('role_id', SchemaColumnType::Integer, primaryKey: true),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('PRIMARY KEY ("user_id", "role_id")', $stmts[0]);
    }

    #[Test]
    public function createWithNoPrimaryKey(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'logs',
            columns: [
                new SchemaColumn('message', SchemaColumnType::Text),
                new SchemaColumn('created_at', SchemaColumnType::DateTime),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringNotContainsString('PRIMARY KEY', $stmts[0]);
    }

    #[Test]
    public function autoIncrementPgsqlSerial(): void
    {
        $compiler = $this->compiler(Driver::PostgreSQL);
        $def = new TableDefinition(
            name: 'items',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('"id" SERIAL', $stmts[0]);
    }

    #[Test]
    public function addColumnReturnsStatement(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $col = new SchemaColumn('email', SchemaColumnType::String, length: 200);
        $stmts = $compiler->compileAlterAddColumn('users', $col);

        self::assertCount(1, $stmts);
        self::assertStringContainsString('ALTER TABLE "users" ADD COLUMN "email" VARCHAR(200)', $stmts[0]);
    }

    #[Test]
    public function dropColumnThrowsOnUnsupportedSqlite(): void
    {
        $compiler = $this->compiler(Driver::SQLite); // no connection, defaults to unsupported
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('not supported');
        $compiler->compileAlterDropColumn('users', 'email');
    }

    #[Test]
    public function dropColumnMysql(): void
    {
        $compiler = $this->compiler(Driver::MySQL);
        $stmts = $compiler->compileAlterDropColumn('users', 'email');
        self::assertCount(1, $stmts);
        self::assertSame('ALTER TABLE `users` DROP COLUMN `email`', $stmts[0]);
    }

    #[Test]
    public function dropIndexMysqlWithTableName(): void
    {
        $compiler = $this->compiler(Driver::MySQL);
        $stmts = $compiler->compileDropIndex('users', 'idx_email');
        self::assertSame('DROP INDEX IF EXISTS `idx_email` ON `users`', $stmts[0]);
    }

    #[Test]
    public function dropIndexPgsqlWithIfExists(): void
    {
        $compiler = $this->compiler(Driver::PostgreSQL);
        $stmts = $compiler->compileDropIndex('users', 'idx_email');
        self::assertSame('DROP INDEX IF EXISTS "idx_email"', $stmts[0]);
    }

    #[Test]
    public function identifierQuotingPerDriver(): void
    {
        $mysqlStmts = $this->compiler(Driver::MySQL)->compileDropTable('users');
        self::assertStringContainsString('`users`', $mysqlStmts[0]);

        $pgsqlStmts = $this->compiler(Driver::PostgreSQL)->compileDropTable('users');
        self::assertStringContainsString('"users"', $pgsqlStmts[0]);
    }

    #[Test]
    public function scalarDefaultsSafelyQuoted(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'cfg',
            columns: [
                new SchemaColumn('count', SchemaColumnType::Integer, hasDefault: true, default: 0),
                new SchemaColumn('ratio', SchemaColumnType::Float, hasDefault: true, default: 1.5),
                new SchemaColumn('label', SchemaColumnType::String, hasDefault: true, default: 'hello'),
                new SchemaColumn('empty', SchemaColumnType::String, nullable: true, hasDefault: true, default: null),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('DEFAULT 0', $stmts[0]);
        self::assertStringContainsString('DEFAULT 1.5', $stmts[0]);
        self::assertStringContainsString("DEFAULT 'hello'", $stmts[0]);
        self::assertStringContainsString('DEFAULT NULL', $stmts[0]);
    }

    #[Test]
    public function defaultExpressionFromAllowlist(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'events',
            columns: [
                new SchemaColumn('created_at', SchemaColumnType::DateTime, defaultExpression: SchemaDefaultExpression::CurrentTimestamp),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $stmts[0]);
    }

    #[Test]
    public function unsignedIgnoredOnPgsqlSqlite(): void
    {
        $pgsqlCompiler = $this->compiler(Driver::PostgreSQL);
        $def = new TableDefinition(
            name: 'nums',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Integer, unsigned: true),
            ],
        );

        $stmts = $pgsqlCompiler->compileCreate($def);
        self::assertStringNotContainsString('UNSIGNED', $stmts[0]);

        // MySQL should include UNSIGNED
        $mysqlCompiler = $this->compiler(Driver::MySQL);
        $stmts = $mysqlCompiler->compileCreate($def);
        self::assertStringContainsString('UNSIGNED', $stmts[0]);
    }

    #[Test]
    public function renameTableMysql(): void
    {
        $stmts = $this->compiler(Driver::MySQL)->compileRenameTable('old_name', 'new_name');
        self::assertSame('RENAME TABLE `old_name` TO `new_name`', $stmts[0]);
    }

    #[Test]
    public function renameTablePgsql(): void
    {
        $stmts = $this->compiler(Driver::PostgreSQL)->compileRenameTable('old_name', 'new_name');
        self::assertSame('ALTER TABLE "old_name" RENAME TO "new_name"', $stmts[0]);
    }

    private function compiler(Driver $driver): DdlCompiler
    {
        return new DdlCompiler($driver, new SchemaCapabilities($driver));
    }
}
