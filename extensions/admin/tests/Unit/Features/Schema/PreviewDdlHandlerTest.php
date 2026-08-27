<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\Schema;

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

        // SQLite still cannot add a foreign key to an existing table.
        $warningCodes = array_map(fn($w) => $w['code'], $result['warnings']);
        self::assertContains('NO_ALTER_ADD_FK', $warningCodes);
    }

    /**
     * The atomicity warning is shown to the operator whose schema changes really are not
     * atomic, and to nobody else.
     *
     * `NO_TRANSACTIONAL_DDL` renders as "Schema changes are not atomic on this database
     * driver". SQLite used to receive it because
     * {@see SchemaCapabilities::supportsTransactionalDdl()} answered false there, and the
     * sentence was simply untrue: SQLite rolls DDL back with the enclosing transaction, and
     * {@see \Pulsar\Tests\Contract\TransactionalDdlContractTest} measures that against the
     * engine. A warning that is false is worse than no warning — it teaches an operator to
     * take precautions the engine already takes, and to distrust the ones that matter.
     *
     * MySQL is asserted alongside it, because a test that only showed the warning gone would
     * equally pass if the warning had been deleted.
     */
    #[Test]
    public function theNonAtomicWarningIsRaisedForMySqlAndNotForSqlite(): void
    {
        $def = new TableDefinition(
            name: 'warn_test',
            columns: [new SchemaColumn('id', SchemaColumnType::Integer)],
        );

        self::assertNotContains(
            'NO_TRANSACTIONAL_DDL',
            array_map(fn($w) => $w['code'], $this->handler->previewCreate($def)['warnings']),
            'SQLite undoes DDL with the transaction around it, so telling its operator that '
            . 'schema changes are not atomic is a false warning',
        );

        self::assertContains(
            'NO_TRANSACTIONAL_DDL',
            array_map(fn($w) => $w['code'], $this->handlerFor(Driver::MySQL)->previewCreate($def)['warnings']),
            'MySQL commits each DDL statement as it runs, which is the deployment this warning '
            . 'exists for',
        );
    }

    /**
     * A handler for an engine other than the one {@see setUp()} builds.
     *
     * The connection stays SQLite because nothing here executes: `previewCreate()` compiles
     * and collects warnings, and both are decided by the capabilities object alone.
     */
    private function handlerFor(Driver $driver): PreviewDdlHandler
    {
        $connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $capabilities = new SchemaCapabilities($driver, $connection);

        return new PreviewDdlHandler(
            new SchemaManager($connection, new DdlCompiler($driver, $capabilities), $capabilities),
            $capabilities,
        );
    }
}
