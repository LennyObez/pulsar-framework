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
final class PostgresSchemaCompilerTest extends TestCase
{
    private DdlCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new DdlCompiler(Driver::PostgreSQL, new SchemaCapabilities(Driver::PostgreSQL));
    }

    /**
     * @return iterable<string, array{SchemaColumnType, string, array{primaryKey?: bool, autoIncrement?: bool, length?: int, precision?: int, scale?: int}}>
     */
    public static function columnTypeProvider(): iterable
    {
        yield 'id (bigserial)' => [
            SchemaColumnType::BigInt,
            'BIGSERIAL',
            ['primaryKey' => true, 'autoIncrement' => true],
        ];
        yield 'uuid' => [SchemaColumnType::Uuid, 'UUID', []];
        yield 'string' => [SchemaColumnType::String, 'VARCHAR(255)', ['length' => 255]];
        yield 'string with length' => [SchemaColumnType::String, 'VARCHAR(100)', ['length' => 100]];
        yield 'text' => [SchemaColumnType::Text, 'TEXT', []];
        yield 'integer' => [SchemaColumnType::Integer, 'INTEGER', []];
        yield 'integer serial' => [
            SchemaColumnType::Integer,
            'SERIAL',
            ['primaryKey' => true, 'autoIncrement' => true],
        ];
        yield 'bigint' => [SchemaColumnType::BigInt, 'BIGINT', []];
        yield 'boolean' => [SchemaColumnType::Boolean, 'BOOLEAN', []];
        yield 'decimal' => [SchemaColumnType::Decimal, 'DECIMAL(10, 2)', ['precision' => 10, 'scale' => 2]];
        yield 'datetime' => [SchemaColumnType::DateTime, 'TIMESTAMP', []];
        yield 'json' => [SchemaColumnType::Json, 'JSONB', []];
        yield 'binary' => [SchemaColumnType::Binary, 'BYTEA', []];
        yield 'float' => [SchemaColumnType::Float, 'DOUBLE PRECISION', []];
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
            $def = new TableDefinition(
                name: 'test',
                columns: [
                    new SchemaColumn('id', $type, primaryKey: true, autoIncrement: true),
                ],
            );
            $stmts = $this->compiler->compileCreate($def);
            self::assertStringContainsString('"id" ' . $expectedSql, $stmts[0]);
            self::assertStringContainsString('PRIMARY KEY', $stmts[0]);

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
    public function idUsesBigserial(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->id();
        $def = $blueprint->toDefinition();
        $stmts = $this->compiler->compileCreate($def);

        self::assertStringContainsString('"id" BIGSERIAL', $stmts[0]);
        self::assertStringContainsString('PRIMARY KEY', $stmts[0]);
    }

    #[Test]
    public function booleanDefaultsUseTrueFalse(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('active', SchemaColumnType::Boolean, hasDefault: true, default: true),
                new SchemaColumn('deleted', SchemaColumnType::Boolean, hasDefault: true, default: false),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString('DEFAULT TRUE', $stmts[0]);
        self::assertStringContainsString('DEFAULT FALSE', $stmts[0]);
    }

    #[Test]
    public function jsonMapsToJsonb(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('data', SchemaColumnType::Json),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString('JSONB', $stmts[0]);
    }

    #[Test]
    public function foreignKeyConstraint(): void
    {
        $def = new TableDefinition(
            name: 'posts',
            columns: [
                new SchemaColumn('id', SchemaColumnType::BigInt, primaryKey: true, autoIncrement: true),
                new SchemaColumn('user_id', SchemaColumnType::BigInt),
            ],
            foreignKeys: [
                new SchemaForeignKey(
                    name: 'fk_posts_user_id',
                    columns: ['user_id'],
                    referencedTable: 'users',
                    referencedColumns: ['id'],
                    onDelete: SchemaReferentialAction::Cascade,
                    onUpdate: SchemaReferentialAction::NoAction,
                ),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString(
            'CONSTRAINT "fk_posts_user_id" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE ON UPDATE NO ACTION',
            $stmts[0],
        );
    }

    #[Test]
    public function renameTableUsesAlterSyntax(): void
    {
        $stmts = $this->compiler->compileRenameTable('old_name', 'new_name');
        self::assertSame('ALTER TABLE "old_name" RENAME TO "new_name"', $stmts[0]);
    }

    #[Test]
    public function dropColumnSupported(): void
    {
        $stmts = $this->compiler->compileAlterDropColumn('users', 'email');
        self::assertSame('ALTER TABLE "users" DROP COLUMN "email"', $stmts[0]);
    }

    #[Test]
    public function dropIndexOmitsTableName(): void
    {
        $stmts = $this->compiler->compileDropIndex('users', 'idx_email');
        self::assertSame('DROP INDEX IF EXISTS "idx_email"', $stmts[0]);
    }

    #[Test]
    public function indexPrefixingForSchemaScoping(): void
    {
        $index = new SchemaIndex('idx_name', ['name']);
        $stmts = $this->compiler->compileAddIndex('users', $index);

        self::assertStringContainsString('"users_idx_name"', $stmts[0]);
    }

    #[Test]
    public function indexPrefixingSkipsAlreadyPrefixed(): void
    {
        $index = new SchemaIndex('users_idx_name', ['name']);
        $stmts = $this->compiler->compileAddIndex('users', $index);

        self::assertStringNotContainsString('"users_users_idx_name"', $stmts[0]);
        self::assertStringContainsString('"users_idx_name"', $stmts[0]);
    }

    #[Test]
    public function enumUsesCheckConstraint(): void
    {
        $def = new TableDefinition(
            name: 'tickets',
            columns: [
                new SchemaColumn('status', SchemaColumnType::Enum, enumValues: ['open', 'closed']),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString("CHECK (\"status\" IN ('open', 'closed'))", $stmts[0]);
    }

    #[Test]
    public function defaultExpressionCurrentTimestamp(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('created_at', SchemaColumnType::DateTime, defaultExpression: SchemaDefaultExpression::CurrentTimestamp),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $stmts[0]);
    }

    #[Test]
    public function uuidDefaultExpression(): void
    {
        self::assertSame(SchemaDefaultExpression::PostgresUuid, $this->compiler->uuidDefaultExpression());
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

        $sql = $stmts[0];
        self::assertStringContainsString('CREATE TABLE "comprehensive"', $sql);
        self::assertStringContainsString('"id" BIGSERIAL', $sql);
        self::assertStringContainsString('"name" VARCHAR(255)', $sql);
        self::assertStringContainsString('"body" TEXT', $sql);
        self::assertStringContainsString('"count" INTEGER', $sql);
        self::assertStringContainsString('"active" BOOLEAN', $sql);
        self::assertStringContainsString('"created_at" TIMESTAMP', $sql);
        self::assertStringContainsString('"data" JSONB', $sql);
        self::assertStringContainsString('DECIMAL(10, 2)', $sql);
        self::assertStringContainsString('"token" UUID', $sql);
    }

    #[Test]
    public function doubleQuoteInIdentifierIsEscaped(): void
    {
        $stmts = $this->compiler->compileDropTable('my"table');
        self::assertStringContainsString('"my""table"', $stmts[0]);
    }

    #[Test]
    public function transactionalDdlSupported(): void
    {
        $caps = new SchemaCapabilities(Driver::PostgreSQL);
        self::assertTrue($caps->supportsTransactionalDdl());
    }
}
