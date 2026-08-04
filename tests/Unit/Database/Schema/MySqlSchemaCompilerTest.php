<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use InvalidArgumentException;
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
use Pulsar\Database\Schema\SchemaForeignKey;
use Pulsar\Database\Schema\SchemaIndex;
use Pulsar\Database\Schema\SchemaReferentialAction;
use Pulsar\Database\Schema\TableDefinition;

use function count;

#[CoversClass(DdlCompiler::class)]
final class MySqlSchemaCompilerTest extends TestCase
{
    private DdlCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new DdlCompiler(Driver::MySQL, new SchemaCapabilities(Driver::MySQL));
    }

    /**
     * @return iterable<string, array{SchemaColumnType, string, array{primaryKey?: bool, autoIncrement?: bool, unsigned?: bool, length?: int, precision?: int, scale?: int}}>
     */
    public static function columnTypeProvider(): iterable
    {
        yield 'id (bigint unsigned auto)' => [
            SchemaColumnType::BigInt,
            'BIGINT',
            ['primaryKey' => true, 'autoIncrement' => true, 'unsigned' => true],
        ];
        yield 'uuid' => [SchemaColumnType::Uuid, 'VARCHAR(36)', []];
        yield 'string' => [SchemaColumnType::String, 'VARCHAR(255)', ['length' => 255]];
        yield 'string with length' => [SchemaColumnType::String, 'VARCHAR(100)', ['length' => 100]];
        yield 'text' => [SchemaColumnType::Text, 'TEXT', []];
        yield 'integer' => [SchemaColumnType::Integer, 'INTEGER', []];
        yield 'bigint' => [SchemaColumnType::BigInt, 'BIGINT', []];
        yield 'boolean' => [SchemaColumnType::Boolean, 'TINYINT(1)', []];
        yield 'decimal' => [SchemaColumnType::Decimal, 'DECIMAL(10, 2)', ['precision' => 10, 'scale' => 2]];
        yield 'datetime' => [SchemaColumnType::DateTime, 'DATETIME(6)', []];
        yield 'json' => [SchemaColumnType::Json, 'JSON', []];
        yield 'binary' => [SchemaColumnType::Binary, 'BLOB', []];
        yield 'float' => [SchemaColumnType::Float, 'FLOAT', []];
        yield 'date' => [SchemaColumnType::Date, 'DATE', []];
        yield 'time' => [SchemaColumnType::Time, 'TIME', []];
        yield 'smallint' => [SchemaColumnType::SmallInt, 'SMALLINT', []];
    }

    /**
     * @param array{primaryKey?: bool, autoIncrement?: bool, unsigned?: bool, length?: int, precision?: int, scale?: int} $extra
     */
    #[Test]
    #[DataProvider('columnTypeProvider')]
    public function columnTypeMapsCorrectly(SchemaColumnType $type, string $expectedSql, array $extra): void
    {
        $isPk = $extra['primaryKey'] ?? false;
        $isAuto = $extra['autoIncrement'] ?? false;

        if ($isPk && $isAuto) {
            $isUnsigned = $extra['unsigned'] ?? false;
            $def = new TableDefinition(
                name: 'test',
                columns: [
                    new SchemaColumn('id', $type, primaryKey: true, autoIncrement: true, unsigned: $isUnsigned),
                ],
            );
            $stmts = $this->compiler->compileCreate($def);
            // MySQL: BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
            self::assertStringContainsString($expectedSql, $stmts[0]);
            self::assertStringContainsString('AUTO_INCREMENT', $stmts[0]);
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
        self::assertStringContainsString('`col` ' . $expectedSql, $stmts[0]);
    }

    #[Test]
    public function usesBacktickIdentifiers(): void
    {
        $stmts = $this->compiler->compileDropTable('my_table');
        self::assertStringContainsString('`my_table`', $stmts[0]);
        self::assertStringNotContainsString('"', $stmts[0]);
    }

    #[Test]
    public function unsignedModifierIncluded(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('val', SchemaColumnType::Integer, unsigned: true),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString('UNSIGNED', $stmts[0]);
    }

    #[Test]
    public function idGeneratesUnsignedAutoIncrementPrimaryKey(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->id();
        $def = $blueprint->toDefinition();
        $stmts = $this->compiler->compileCreate($def);

        $sql = $stmts[0];
        self::assertStringContainsString('`id` BIGINT', $sql);
        self::assertStringContainsString('PRIMARY KEY', $sql);
        self::assertStringContainsString('AUTO_INCREMENT', $sql);
    }

    #[Test]
    public function foreignKeyConstraint(): void
    {
        $def = new TableDefinition(
            name: 'posts',
            columns: [
                new SchemaColumn('id', SchemaColumnType::BigInt, primaryKey: true, autoIncrement: true),
                new SchemaColumn('user_id', SchemaColumnType::BigInt, unsigned: true),
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
            'CONSTRAINT `fk_posts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT',
            $stmts[0],
        );
    }

    #[Test]
    public function enumUsesNativeEnumType(): void
    {
        $def = new TableDefinition(
            name: 'tickets',
            columns: [
                new SchemaColumn('status', SchemaColumnType::Enum, enumValues: ['open', 'closed', 'pending']),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString("ENUM('open', 'closed', 'pending')", $stmts[0]);
    }

    #[Test]
    public function renameTableUsesRenameTableSyntax(): void
    {
        $stmts = $this->compiler->compileRenameTable('old_name', 'new_name');
        self::assertSame('RENAME TABLE `old_name` TO `new_name`', $stmts[0]);
    }

    #[Test]
    public function dropColumnSupported(): void
    {
        $stmts = $this->compiler->compileAlterDropColumn('users', 'email');
        self::assertSame('ALTER TABLE `users` DROP COLUMN `email`', $stmts[0]);
    }

    /**
     * MySQL names the owning table and accepts no `IF EXISTS` — the clause is a parse
     * error, not a tolerated no-op.
     */
    #[Test]
    public function dropIndexIncludesTableName(): void
    {
        $stmts = $this->compiler->compileDropIndex('users', 'idx_email');
        self::assertSame('DROP INDEX `idx_email` ON `users`', $stmts[0]);
    }

    #[Test]
    public function booleanDefaultsUseNumeric(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('active', SchemaColumnType::Boolean, hasDefault: true, default: true),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringContainsString('DEFAULT 1', $stmts[0]);
    }

    /**
     * A name carrying the delimiter is refused, not escaped.
     *
     * Escaping and validation are different postures, and this is the stronger one.
     * Escaping accepts any name and stakes correctness on doubling the delimiter every
     * time, everywhere; validation refuses any name that could carry a delimiter, a
     * comment introducer or a statement separator at all, so there is nothing left to get
     * right. Identifiers cannot be bound as parameters, which is exactly why the
     * conservative answer is the correct one — and the name arriving here may have come
     * from a user through the admin schema editor.
     */
    #[Test]
    public function backtickInIdentifierIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid SQL identifier');

        $this->compiler->compileDropTable('my`table');
    }

    #[Test]
    public function createTableWithIndexesAndForeignKeys(): void
    {
        $blueprint = new Blueprint('orders');
        $blueprint->id();
        $blueprint->string('reference', 50)->unique();
        $blueprint->foreignId('customer_id')->references('id')->on('customers');
        $blueprint->decimal('total', 12, 2);
        $blueprint->boolean('paid')->default(false);
        $blueprint->timestamp('placed_at');
        $blueprint->index('placed_at');
        $def = $blueprint->toDefinition();

        $stmts = $this->compiler->compileCreate($def);
        // CREATE TABLE + 1 non-unique index
        self::assertGreaterThanOrEqual(2, count($stmts));

        $createSql = $stmts[0];
        self::assertStringContainsString('CREATE TABLE `orders`', $createSql);
        self::assertStringContainsString('FOREIGN KEY', $createSql);
        self::assertStringContainsString('UNIQUE', $createSql);
    }

    #[Test]
    public function nonUniqueIndexAsSeparateStatement(): void
    {
        $def = new TableDefinition(
            name: 'users',
            columns: [
                new SchemaColumn('id', SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
                new SchemaColumn('name', SchemaColumnType::String),
            ],
            indexes: [
                new SchemaIndex('idx_users_name', ['name']),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertCount(2, $stmts);
        self::assertStringContainsString('CREATE INDEX `idx_users_name` ON `users` (`name`)', $stmts[1]);
    }

    #[Test]
    public function nullableColumnOmitsNotNull(): void
    {
        $def = new TableDefinition(
            name: 'test',
            columns: [
                new SchemaColumn('bio', SchemaColumnType::Text, nullable: true),
            ],
        );

        $stmts = $this->compiler->compileCreate($def);
        self::assertStringNotContainsString('NOT NULL', $stmts[0]);
    }
}
