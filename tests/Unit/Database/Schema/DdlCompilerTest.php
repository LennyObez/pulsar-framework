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

    #[Test]
    public function dropTableReturnsDropStatement(): void
    {
        $stmts = $this->compiler(Driver::SQLite)->compileDropTable('old_table');
        self::assertCount(1, $stmts);
        self::assertSame('DROP TABLE IF EXISTS "old_table"', $stmts[0]);
    }

    #[Test]
    public function addIndexNonUniqueReturnsCreateIndex(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $index = new SchemaIndex('idx_name', ['name']);
        $stmts = $compiler->compileAddIndex('users', $index);

        self::assertCount(1, $stmts);
        self::assertStringContainsString('CREATE INDEX', $stmts[0]);
        self::assertStringContainsString('"idx_name"', $stmts[0]);
    }

    #[Test]
    public function addIndexUniqueReturnsUniqueIndex(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $index = new SchemaIndex('idx_email', ['email'], unique: true);
        $stmts = $compiler->compileAddIndex('users', $index);

        self::assertCount(1, $stmts);
        self::assertStringContainsString('UNIQUE INDEX', $stmts[0]);
    }

    #[Test]
    public function dropIndexSqliteReturnsCorrectSyntax(): void
    {
        $stmts = $this->compiler(Driver::SQLite)->compileDropIndex('users', 'idx_email');
        self::assertSame('DROP INDEX IF EXISTS "idx_email"', $stmts[0]);
    }

    #[Test]
    public function renameTableSqliteUsesAlterSyntax(): void
    {
        $stmts = $this->compiler(Driver::SQLite)->compileRenameTable('old', 'new');
        self::assertSame('ALTER TABLE "old" RENAME TO "new"', $stmts[0]);
    }

    #[Test]
    public function typeMapSmallInt(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::SmallInt),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('SMALLINT', $stmts[0]);
    }

    #[Test]
    public function typeMapBigIntMysql(): void
    {
        $compiler = $this->compiler(Driver::MySQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::BigInt),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('BIGINT', $stmts[0]);
    }

    #[Test]
    public function typeMapFloatPgsql(): void
    {
        $compiler = $this->compiler(Driver::PostgreSQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Float),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('DOUBLE PRECISION', $stmts[0]);
    }

    #[Test]
    public function typeMapFloatMysql(): void
    {
        $compiler = $this->compiler(Driver::MySQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Float),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('FLOAT', $stmts[0]);
    }

    #[Test]
    public function typeMapDecimal(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Decimal, precision: 10, scale: 4),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('DECIMAL(10, 4)', $stmts[0]);
    }

    #[Test]
    public function typeMapDate(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Date),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('DATE', $stmts[0]);
    }

    #[Test]
    public function typeMapTime(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Time),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('TIME', $stmts[0]);
    }

    #[Test]
    public function typeMapUuidPgsql(): void
    {
        $compiler = $this->compiler(Driver::PostgreSQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Uuid),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('UUID', $stmts[0]);
    }

    #[Test]
    public function typeMapUuidMysql(): void
    {
        $compiler = $this->compiler(Driver::MySQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Uuid),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('VARCHAR(36)', $stmts[0]);
    }

    #[Test]
    public function typeMapBinaryPgsql(): void
    {
        $compiler = $this->compiler(Driver::PostgreSQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Binary),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('BYTEA', $stmts[0]);
    }

    #[Test]
    public function typeMapBinaryMysql(): void
    {
        $compiler = $this->compiler(Driver::MySQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Binary),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('BLOB', $stmts[0]);
    }

    #[Test]
    public function typeMapJsonMysql(): void
    {
        $compiler = $this->compiler(Driver::MySQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Json),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('JSON', $stmts[0]);
    }

    #[Test]
    public function typeMapJsonSqlite(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Json),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('TEXT', $stmts[0]);
    }

    #[Test]
    public function typeMapDateTimePgsql(): void
    {
        $compiler = $this->compiler(Driver::PostgreSQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::DateTime),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('TIMESTAMP', $stmts[0]);
    }

    #[Test]
    public function typeMapDateTimeMysql(): void
    {
        $compiler = $this->compiler(Driver::MySQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::DateTime),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('DATETIME', $stmts[0]);
    }

    #[Test]
    public function booleanDefaultPgsqlFormat(): void
    {
        $compiler = $this->compiler(Driver::PostgreSQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('active', SchemaColumnType::Boolean, hasDefault: true, default: true),
                new SchemaColumn('deleted', SchemaColumnType::Boolean, hasDefault: true, default: false),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('DEFAULT TRUE', $stmts[0]);
        self::assertStringContainsString('DEFAULT FALSE', $stmts[0]);
    }

    #[Test]
    public function booleanDefaultMysqlFormat(): void
    {
        $compiler = $this->compiler(Driver::MySQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('active', SchemaColumnType::Boolean, hasDefault: true, default: true),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('DEFAULT 1', $stmts[0]);
    }

    #[Test]
    public function enumWithEmptyValuesFallsBackToVarchar(): void
    {
        $compiler = $this->compiler(Driver::MySQL);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('status', SchemaColumnType::Enum, enumValues: [], length: 100),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('VARCHAR(100)', $stmts[0]);
    }

    #[Test]
    public function uniqueIndexInCreateTable(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'users',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('email', SchemaColumnType::String),
            ],
            indexes: [
                new SchemaIndex('idx_email', ['email'], unique: true),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertCount(1, $stmts); // Unique indexes are inline, not separate
        self::assertStringContainsString('CONSTRAINT "idx_email" UNIQUE', $stmts[0]);
    }

    #[Test]
    public function pgsqlIndexPrefixing(): void
    {
        $compiler = $this->compiler(Driver::PostgreSQL);
        $index = new SchemaIndex('idx_name', ['name']);
        $stmts = $compiler->compileAddIndex('users', $index);

        self::assertStringContainsString('"users_idx_name"', $stmts[0]);
    }

    #[Test]
    public function pgsqlIndexPrefixingSkipsAlreadyPrefixed(): void
    {
        $compiler = $this->compiler(Driver::PostgreSQL);
        $index = new SchemaIndex('users_idx_name', ['name']);
        $stmts = $compiler->compileAddIndex('users', $index);

        // Should not double-prefix
        self::assertStringNotContainsString('"users_users_idx_name"', $stmts[0]);
        self::assertStringContainsString('"users_idx_name"', $stmts[0]);
    }

    #[Test]
    public function dropColumnPgsql(): void
    {
        $stmts = $this->compiler(Driver::PostgreSQL)->compileAlterDropColumn('users', 'email');
        self::assertSame('ALTER TABLE "users" DROP COLUMN "email"', $stmts[0]);
    }

    #[Test]
    public function singlePrimaryKeyNotAutoIncrement(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true),
                new SchemaColumn('name', SchemaColumnType::String),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('PRIMARY KEY ("id")', $stmts[0]);
        self::assertStringNotContainsString('AUTOINCREMENT', $stmts[0]);
    }

    #[Test]
    public function stringWithEscapedQuoteInDefault(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('label', SchemaColumnType::String, hasDefault: true, default: "it's"),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString("DEFAULT 'it''s'", $stmts[0]);
    }

    #[Test]
    public function nullableColumnOmitsNotNull(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('description', SchemaColumnType::Text, nullable: true),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringNotContainsString('NOT NULL', $stmts[0]);
    }

    #[Test]
    public function nonNullableColumnIncludesNotNull(): void
    {
        $compiler = $this->compiler(Driver::SQLite);
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('name', SchemaColumnType::String),
            ],
        );

        $stmts = $compiler->compileCreate($def);
        self::assertStringContainsString('NOT NULL', $stmts[0]);
    }

    private function compiler(Driver $driver): DdlCompiler
    {
        return new DdlCompiler($driver, new SchemaCapabilities($driver));
    }
}
