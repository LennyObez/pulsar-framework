<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Database\Schema\Blueprint;
use Pulsar\Database\Schema\ColumnBuilder;
use Pulsar\Database\Schema\SchemaBuilder;
use Pulsar\Tests\Unit\Database\Stub\InMemoryConnection;

#[CoversClass(SchemaBuilder::class)]
#[CoversClass(Blueprint::class)]
#[CoversClass(ColumnBuilder::class)]
final class SchemaBuilderTest extends TestCase
{
    /**
     * @return iterable<string, array{Driver}>
     */
    public static function driverProvider(): iterable
    {
        yield 'MySQL' => [Driver::MySQL];
        yield 'PostgreSQL' => [Driver::PostgreSQL];
        yield 'SQLite' => [Driver::SQLite];
    }

    #[Test]
    #[DataProvider('driverProvider')]
    public function createTableGeneratesValidDdl(Driver $driver): void
    {
        $conn = new InMemoryConnection($driver);
        $schema = SchemaBuilder::for($conn);

        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email', 191)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $executed = $conn->executedStatements();
        self::assertNotEmpty($executed);

        // Verify the CREATE TABLE statement was generated
        $createStmt = $executed[0];
        self::assertStringContainsString('CREATE TABLE', $createStmt);
        // MySQL uses backticks, PostgreSQL/SQLite use double quotes
        $quoted = match ($driver) {
            Driver::MySQL => '`users`',
            Driver::PostgreSQL, Driver::SQLite => '"users"',
        };
        self::assertStringContainsString($quoted, $createStmt);
    }

    #[Test]
    #[DataProvider('driverProvider')]
    public function dropTableGeneratesDropStatement(Driver $driver): void
    {
        $conn = new InMemoryConnection($driver);
        $schema = SchemaBuilder::for($conn);

        $schema->drop('users');

        $executed = $conn->executedStatements();
        self::assertCount(1, $executed);
        self::assertStringContainsString('DROP TABLE IF EXISTS', $executed[0]);
    }

    #[Test]
    #[DataProvider('driverProvider')]
    public function dropIfExistsIsAliasForDrop(Driver $driver): void
    {
        $conn = new InMemoryConnection($driver);
        $schema = SchemaBuilder::for($conn);

        $schema->dropIfExists('posts');

        $executed = $conn->executedStatements();
        self::assertCount(1, $executed);
        self::assertStringContainsString('DROP TABLE IF EXISTS', $executed[0]);
    }

    #[Test]
    public function renameTableMysql(): void
    {
        $conn = new InMemoryConnection(Driver::MySQL);
        $schema = SchemaBuilder::for($conn);

        $schema->rename('old_table', 'new_table');

        $executed = $conn->executedStatements();
        self::assertCount(1, $executed);
        self::assertStringContainsString('RENAME TABLE', $executed[0]);
    }

    #[Test]
    public function renameTablePostgresql(): void
    {
        $conn = new InMemoryConnection(Driver::PostgreSQL);
        $schema = SchemaBuilder::for($conn);

        $schema->rename('old_table', 'new_table');

        $executed = $conn->executedStatements();
        self::assertCount(1, $executed);
        self::assertStringContainsString('ALTER TABLE', $executed[0]);
        self::assertStringContainsString('RENAME TO', $executed[0]);
    }

    #[Test]
    public function tableAddsColumnsViaAlterTable(): void
    {
        $conn = new InMemoryConnection(Driver::SQLite);
        $schema = SchemaBuilder::for($conn);

        $schema->table('users', function (Blueprint $table): void {
            $table->string('phone')->nullable();
        });

        $executed = $conn->executedStatements();
        self::assertNotEmpty($executed);
        self::assertStringContainsString('ALTER TABLE', $executed[0]);
        self::assertStringContainsString('ADD COLUMN', $executed[0]);
    }

    #[Test]
    public function previewReturnsStatementsWithoutExecuting(): void
    {
        $conn = new InMemoryConnection(Driver::MySQL);
        $schema = SchemaBuilder::for($conn);

        $statements = $schema->preview('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        self::assertNotEmpty($statements);
        self::assertStringContainsString('CREATE TABLE', $statements[0]);
        // Nothing should have been executed
        self::assertEmpty($conn->executedStatements());
    }

    #[Test]
    public function createTableWithForeignKey(): void
    {
        $conn = new InMemoryConnection(Driver::SQLite);
        $schema = SchemaBuilder::for($conn);

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->foreignId('user_id')->references('id')->on('users');
        });

        $executed = $conn->executedStatements();
        $createStmt = $executed[0];
        self::assertStringContainsString('FOREIGN KEY', $createStmt);
        self::assertStringContainsString('REFERENCES', $createStmt);
    }

    #[Test]
    public function mysqlUsesBacktickQuoting(): void
    {
        $conn = new InMemoryConnection(Driver::MySQL);
        $schema = SchemaBuilder::for($conn);

        $statements = $schema->preview('users', function (Blueprint $table): void {
            $table->id();
        });

        self::assertStringContainsString('`users`', $statements[0]);
    }

    #[Test]
    public function postgresqlUsesDoubleQuoteQuoting(): void
    {
        $conn = new InMemoryConnection(Driver::PostgreSQL);
        $schema = SchemaBuilder::for($conn);

        $statements = $schema->preview('users', function (Blueprint $table): void {
            $table->id();
        });

        self::assertStringContainsString('"users"', $statements[0]);
    }

    #[Test]
    public function connectionReturnsUnderlyingConnection(): void
    {
        $conn = new InMemoryConnection(Driver::SQLite);
        $schema = SchemaBuilder::for($conn);

        self::assertSame($conn, $schema->connection());
    }

    #[Test]
    public function capabilitiesReturnsSchemaCapabilities(): void
    {
        $conn = new InMemoryConnection(Driver::PostgreSQL);
        $schema = SchemaBuilder::for($conn);

        self::assertTrue($schema->capabilities()->supportsTransactionalDdl());
    }

    #[Test]
    public function mysqlBooleanMapsToBool(): void
    {
        $conn = new InMemoryConnection(Driver::MySQL);
        $schema = SchemaBuilder::for($conn);

        $statements = $schema->preview('flags', function (Blueprint $table): void {
            $table->boolean('active')->default(false);
        });

        self::assertStringContainsString('TINYINT(1)', $statements[0]);
    }

    #[Test]
    public function postgresqlBooleanMapsToBoolean(): void
    {
        $conn = new InMemoryConnection(Driver::PostgreSQL);
        $schema = SchemaBuilder::for($conn);

        $statements = $schema->preview('flags', function (Blueprint $table): void {
            $table->boolean('active');
        });

        self::assertStringContainsString('BOOLEAN', $statements[0]);
    }

    #[Test]
    public function postgresqlBigIntAutoIncrementUsesBigserial(): void
    {
        $conn = new InMemoryConnection(Driver::PostgreSQL);
        $schema = SchemaBuilder::for($conn);

        $statements = $schema->preview('users', function (Blueprint $table): void {
            $table->id();
        });

        self::assertStringContainsString('BIGSERIAL', $statements[0]);
    }

    #[Test]
    public function postgresqlJsonMapsToJsonb(): void
    {
        $conn = new InMemoryConnection(Driver::PostgreSQL);
        $schema = SchemaBuilder::for($conn);

        $statements = $schema->preview('configs', function (Blueprint $table): void {
            $table->json('data');
        });

        self::assertStringContainsString('JSONB', $statements[0]);
    }

    #[Test]
    public function sqliteJsonMapsToText(): void
    {
        $conn = new InMemoryConnection(Driver::SQLite);
        $schema = SchemaBuilder::for($conn);

        $statements = $schema->preview('configs', function (Blueprint $table): void {
            $table->json('data');
        });

        // Should not contain JSONB, should use TEXT
        self::assertStringNotContainsString('JSONB', $statements[0]);
    }

    #[Test]
    public function hasTableReturnsTrueWhenTableExists(): void
    {
        $conn = new InMemoryConnection(Driver::SQLite);
        $conn->enqueueQueryResult(new Result([new Row(['cnt' => 1])]));
        $schema = SchemaBuilder::for($conn);

        self::assertTrue($schema->hasTable('users'));
    }

    #[Test]
    public function hasTableReturnsFalseWhenTableDoesNotExist(): void
    {
        $conn = new InMemoryConnection(Driver::SQLite);
        $conn->enqueueQueryResult(new Result([new Row(['cnt' => 0])]));
        $schema = SchemaBuilder::for($conn);

        self::assertFalse($schema->hasTable('missing_table'));
    }

    #[Test]
    #[DataProvider('driverProvider')]
    public function hasTableQueriesCorrectCatalogPerDriver(Driver $driver): void
    {
        $conn = new InMemoryConnection($driver);
        $conn->enqueueQueryResult(new Result([new Row(['cnt' => 0])]));
        $schema = SchemaBuilder::for($conn);

        $schema->hasTable('some_table');

        $executed = $conn->executedStatements();
        self::assertCount(1, $executed);

        $sql = $executed[0];
        match ($driver) {
            Driver::SQLite => self::assertStringContainsString('sqlite_master', $sql),
            Driver::MySQL => self::assertStringContainsString('information_schema.tables', $sql),
            Driver::PostgreSQL => self::assertStringContainsString('information_schema.tables', $sql),
        };
    }

    #[Test]
    public function hasTableReturnsFalseOnEmptyResult(): void
    {
        $conn = new InMemoryConnection(Driver::SQLite);
        // Return empty result (no rows)
        $conn->enqueueQueryResult(new Result([]));
        $schema = SchemaBuilder::for($conn);

        self::assertFalse($schema->hasTable('test'));
    }

    #[Test]
    public function dropColumnDelegatesToCompiler(): void
    {
        $conn = new InMemoryConnection(Driver::MySQL);
        $schema = SchemaBuilder::for($conn);

        $schema->dropColumn('users', 'legacy_field');

        $executed = $conn->executedStatements();
        self::assertCount(1, $executed);
        self::assertStringContainsString('ALTER TABLE', $executed[0]);
        self::assertStringContainsString('DROP COLUMN', $executed[0]);
    }
}
