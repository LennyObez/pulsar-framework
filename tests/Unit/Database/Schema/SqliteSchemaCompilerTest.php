<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Schema\Blueprint;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaDefaultExpression;
use Pulsar\Database\Schema\SchemaForeignKey;
use Pulsar\Database\Schema\SchemaIndex;
use Pulsar\Database\Schema\SchemaReferentialAction;
use Pulsar\Database\Schema\TableDefinition;

#[CoversClass(DdlCompiler::class)]
final class SqliteSchemaCompilerTest extends TestCase
{
    private DdlCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new DdlCompiler(Driver::SQLite, new SchemaCapabilities(Driver::SQLite));
    }

    /**
     * @return iterable<string, array{SchemaColumnType, string, array{primaryKey?: bool, autoIncrement?: bool, length?: int, precision?: int, scale?: int}}>
     */
    public static function columnTypeProvider(): iterable
    {
        yield 'id (bigint auto)' => [
            SchemaColumnType::BigInt,
            'BIGINT PRIMARY KEY AUTOINCREMENT',
            ['primaryKey' => true, 'autoIncrement' => true],
        ];
        yield 'uuid' => [SchemaColumnType::Uuid, 'VARCHAR(36)', []];
        yield 'string' => [SchemaColumnType::String, 'VARCHAR(255)', ['length' => 255]];
        yield 'string with length' => [SchemaColumnType::String, 'VARCHAR(100)', ['length' => 100]];
        yield 'text' => [SchemaColumnType::Text, 'TEXT', []];
        yield 'integer' => [SchemaColumnType::Integer, 'INTEGER', []];
        yield 'bigint' => [SchemaColumnType::BigInt, 'BIGINT', []];
        yield 'boolean' => [SchemaColumnType::Boolean, 'INTEGER', []];
        yield 'decimal' => [SchemaColumnType::Decimal, 'DECIMAL(10, 2)', ['precision' => 10, 'scale' => 2]];
        yield 'datetime' => [SchemaColumnType::DateTime, 'DATETIME', []];
        yield 'json' => [SchemaColumnType::Json, 'TEXT', []];
        yield 'binary' => [SchemaColumnType::Binary, 'BLOB', []];
        yield 'float' => [SchemaColumnType::Float, 'FLOAT', []];
        yield 'date' => [SchemaColumnType::Date, 'DATE', []];
        yield 'time' => [SchemaColumnType::Time, 'TIME', []];
        yield 'smallint' => [SchemaColumnType::SmallInt, 'SMALLINT', []];
    }

    /**
     * @param array{primaryKey?: bool, autoIncrement?: bool, length?: int, precision?: int, scale?: int} $extra
     */
    #[Test]
    #[DataProvider('columnTypeProvider')]
    public function columnTypeMapsCorrectly(SchemaColumnType $type, string $expectedSql, array $extra): void
    {
        $isPk = $extra['primaryKey'] ?? false;
        $isAuto = $extra['autoIncrement'] ?? false;

        if ($isPk && $isAuto) {
            // Auto-increment PK goes inline, verify full CREATE TABLE
            $def = new TableDefinition(
                name: 'test',
                columns: [
                    new SchemaColumn('id', $type, primaryKey: true, autoIncrement: true),
                ],
            );
            $stmts = $this->compiler->compileCreate($def);
            self::assertStringContainsString($expectedSql, $stmts[0]);

            return;
        }

        $length = $extra['length'] ?? null;
        $precision = $extra['precision'] ?? null;
        $scale = $extra['scale'] ?? null;

        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn(
                    'col',
                    $type,
                    length: $length,
                    precision: $precision,
                    scale: $scale,
                ),
            ],
        );
        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString('"col" ' . $expectedSql, $stmts[0]);
    }

    #[Test]
    public function usesDoubleQuoteIdentifiers(): void
    {
        $stmts = $this->compiler->compileDropTable('my_table');
        self::assertStringContainsString('"my_table"', $stmts[0]);
        self::assertStringNotContainsString('`', $stmts[0]);
    }

    #[Test]
    public function createTableWithAllColumnTypes(): void
    {
        $blueprint = new Blueprint('comprehensive');
        $blueprint->id();
        $blueprint->string('name');
        $blueprint->text('body');
        $blueprint->integer('count');
        $blueprint->boolean('active');
        $blueprint->timestamp('created_at');
        $blueprint->json('data');
        $blueprint->decimal('price', 10, 2);
        $blueprint->uuid('token');
        $def = $blueprint->toDefinition();

        $stmts = $this->compiler->compileCreate($def);
        self::assertCount(1, $stmts);

        $sql = $stmts[0];
        self::assertStringContainsString('CREATE TABLE "comprehensive"', $sql);
        self::assertStringContainsString('BIGINT PRIMARY KEY AUTOINCREMENT', $sql);
        self::assertStringContainsString('"name" VARCHAR(255)', $sql);
        self::assertStringContainsString('"body" TEXT', $sql);
        self::assertStringContainsString('"count" INTEGER', $sql);
        self::assertStringContainsString('"active" INTEGER', $sql);
        self::assertStringContainsString('"created_at" DATETIME', $sql);
        self::assertStringContainsString('"data" TEXT', $sql);
        self::assertStringContainsString('"price" DECIMAL(10, 2)', $sql);
        self::assertStringContainsString('"token" VARCHAR(36)', $sql);
    }

    #[Test]
    public function foreignKeyConstraintInCreateTable(): void
    {
        $def = new TableDefinition(
            name: 'posts',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('user_id', SchemaColumnType::BigInt),
            ],
            foreignKeys: [
                new SchemaForeignKey(
                    name: 'fk_posts_user_id',
                    columns: ['user_id'],
                    referencedTable: 'users',
                    referencedColumns: ['id'],
                    onDelete: SchemaReferentialAction::Cascade,
                    onUpdate: SchemaReferentialAction::Restrict,
                ),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString(
            'CONSTRAINT "fk_posts_user_id" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE ON UPDATE RESTRICT',
            $stmts[0],
        );
    }

    #[Test]
    public function nonUniqueIndexAsSeparateStatement(): void
    {
        $def = new TableDefinition(
            name: 'users',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('email', SchemaColumnType::String),
            ],
            indexes: [
                new SchemaIndex('idx_users_email', ['email']),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertCount(2, $stmts);
        self::assertStringContainsString('CREATE INDEX "idx_users_email" ON "users" ("email")', $stmts[1]);
    }

    #[Test]
    public function uniqueIndexInlineInCreateTable(): void
    {
        $def = new TableDefinition(
            name: 'users',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('email', SchemaColumnType::String),
            ],
            indexes: [
                new SchemaIndex('uq_users_email', ['email'], unique: true),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertCount(1, $stmts);
        self::assertStringContainsString('CONSTRAINT "uq_users_email" UNIQUE ("email")', $stmts[0]);
    }

    #[Test]
    public function dropTableUsesIfExists(): void
    {
        $stmts = $this->compiler->compileDropTable('users');
        self::assertSame('DROP TABLE IF EXISTS "users"', $stmts[0]);
    }

    #[Test]
    public function renameTableUsesAlterSyntax(): void
    {
        $stmts = $this->compiler->compileRenameTable('old_name', 'new_name');
        self::assertSame('ALTER TABLE "old_name" RENAME TO "new_name"', $stmts[0]);
    }

    #[Test]
    public function nullableColumnOmitsNotNull(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('note', SchemaColumnType::Text, nullable: true),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringNotContainsString('NOT NULL', $stmts[0]);
    }

    #[Test]
    public function nonNullableColumnIncludesNotNull(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('name', SchemaColumnType::String),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString('NOT NULL', $stmts[0]);
    }

    #[Test]
    public function defaultCurrentTimestamp(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('created_at', SchemaColumnType::DateTime, nullable: true, defaultExpression: SchemaDefaultExpression::CurrentTimestamp),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $stmts[0]);
    }

    #[Test]
    public function stringDefaultValueIsQuoted(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('role', SchemaColumnType::String, hasDefault: true, default: 'user'),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString("DEFAULT 'user'", $stmts[0]);
    }

    #[Test]
    public function integerDefaultValueIsNotQuoted(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('count', SchemaColumnType::Integer, hasDefault: true, default: 42),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString('DEFAULT 42', $stmts[0]);
    }

    #[Test]
    public function compositePrimaryKey(): void
    {
        $def = new TableDefinition(
            name: 'pivot',
            columns: [
                new SchemaColumn('user_id', SchemaColumnType::Integer, primaryKey: true),
                new SchemaColumn('role_id', SchemaColumnType::Integer, primaryKey: true),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString('PRIMARY KEY ("user_id", "role_id")', $stmts[0]);
    }

    #[Test]
    public function unsignedIsIgnored(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Integer, unsigned: true),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringNotContainsString('UNSIGNED', $stmts[0]);
    }

    #[Test]
    public function alterAddColumn(): void
    {
        $col = new SchemaColumn('phone', SchemaColumnType::String, nullable: true, length: 20);
        $stmts = $this->compiler->compileAlterAddColumn('users', $col);

        self::assertCount(1, $stmts);
        self::assertSame('ALTER TABLE "users" ADD COLUMN "phone" VARCHAR(20)', $stmts[0]);
    }

    #[Test]
    public function dropIndexSqliteSyntax(): void
    {
        $stmts = $this->compiler->compileDropIndex('users', 'idx_email');
        self::assertSame('DROP INDEX IF EXISTS "idx_email"', $stmts[0]);
    }

    #[Test]
    public function singleQuoteInDefaultIsEscaped(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('note', SchemaColumnType::String, hasDefault: true, default: "it's"),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString("DEFAULT 'it''s'", $stmts[0]);
    }

    #[Test]
    public function booleanDefaultsUseNumeric(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('active', SchemaColumnType::Boolean, hasDefault: true, default: true),
                new SchemaColumn('deleted', SchemaColumnType::Boolean, hasDefault: true, default: false),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString('DEFAULT 1', $stmts[0]);
        self::assertStringContainsString('DEFAULT 0', $stmts[0]);
    }

    #[Test]
    public function identifierWithDoubleQuoteIsEscaped(): void
    {
        $stmts = $this->compiler->compileDropTable('my"table');
        self::assertStringContainsString('"my""table"', $stmts[0]);
    }
}
