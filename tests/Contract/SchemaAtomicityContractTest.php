<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaIndex;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\TableDefinition;
use Pulsar\Database\Schema\TableIntrospector;
use Throwable;

use function sprintf;

/**
 * What {@see SchemaManager} leaves behind when a multi-statement schema change fails
 * halfway.
 *
 * `createTable()` is not one statement. A definition carrying a non-unique index compiles
 * to a `CREATE TABLE` followed by a `CREATE INDEX`, and if the second fails the first has
 * either been withdrawn or is now permanent. `SchemaManager` decides which by asking
 * {@see SchemaCapabilities::supportsTransactionalDdl()} whether to wrap the batch.
 *
 * ## Why this test exists at all
 *
 * Correcting SQLite's answer from false to true changed behaviour here, and a behaviour
 * change without a test is indistinguishable from a side effect. Before the correction a
 * failed `createTable()` on SQLite left a bare, index-less table behind and the next caller
 * met a table that half exists; now the batch is withdrawn whole. That is the improvement
 * the correction buys, and it is asserted rather than assumed.
 *
 * ## The expectation is the engine's, not the capability's
 *
 * {@see survivesAFailedBatch()} hard-codes what each engine does, and deliberately does not
 * read the capability back. A test that asserted "the outcome matches whatever
 * `supportsTransactionalDdl()` says" would agree with that method however it was set — it
 * would prove `SchemaManager` obeys the capability and nothing about whether obeying it is
 * right, so reverting SQLite to `false` would leave this suite green over a
 * `createTable()` that strands half a table again.
 *
 * Stating the engines instead makes both mutations visible here: take the wrap out of
 * `SchemaManager::executeStatements()` and the two transactional engines fail; put SQLite's
 * capability back to `false` and SQLite fails, because the manager stops wrapping a batch
 * the engine would have withdrawn. That the hard-coded expectation is itself the truth is
 * enforced next door, by {@see TransactionalDdlContractTest}, which performs the experiment
 * against the live server instead of asserting from a table.
 */
final class SchemaAtomicityContractTest extends TestCase
{
    private const string TABLE = 'schema_atomicity_probe';

    private ?ConnectionInterface $connection = null;

    /**
     * @return iterable<string, array{Driver}>
     */
    public static function engines(): iterable
    {
        foreach (DatabaseEngine::all() as $driver) {
            yield $driver->value => [$driver];
        }
    }

    protected function tearDown(): void
    {
        $this->connection?->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->connection = null;
    }

    /**
     * A `CREATE TABLE` whose trailing `CREATE INDEX` fails.
     *
     * The definition declares the same index name twice, so the compiler emits the same
     * `CREATE INDEX` twice and the second is rejected as a duplicate on all three engines.
     * The table is therefore created and then the batch fails, which is the only
     * interleaving that can distinguish an atomic batch from a sequential one — a failure
     * on the *first* statement would leave nothing behind anywhere and prove nothing.
     *
     * A duplicate name is used rather than an index over a column the table lacks, which
     * was the first attempt and is not portable: SQLite falls back to reading a
     * double-quoted identifier that resolves to nothing as a string literal, so
     * `CREATE INDEX ... ("absent")` is accepted there as an index over a constant
     * expression while MySQL and PostgreSQL reject it. A test whose failure injection only
     * fires on two engines of three measures nothing on the third.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aFailedCreateLeavesNoTableExactlyWhereDdlIsTransactional(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $capabilities = new SchemaCapabilities($driver, $connection, $connection->variant());
        $manager = new SchemaManager($connection, new DdlCompiler($driver, $capabilities), $capabilities);

        $definition = new TableDefinition(
            name: self::TABLE,
            columns: [
                new SchemaColumn(name: 'id', type: SchemaColumnType::Integer, primaryKey: true),
            ],
            // Declared twice under one name, so the second CREATE INDEX is rejected as a
            // duplicate after the CREATE TABLE has already run.
            indexes: [
                new SchemaIndex(name: 'schema_atomicity_probe_idx', columns: ['id']),
                new SchemaIndex(name: 'schema_atomicity_probe_idx', columns: ['id']),
            ],
        );

        $failed = false;

        try {
            $manager->createTable($definition);
        } catch (Throwable) {
            $failed = true;
        }

        self::assertTrue(
            $failed,
            'the second CREATE INDEX repeats the first index name, so createTable() must fail — '
            . 'without that failure this test asserts nothing',
        );

        self::assertSame(
            $this->survivesAFailedBatch($driver),
            new TableIntrospector($connection)->tableExists(self::TABLE),
            sprintf(
                'On %s a createTable() that failed on its second statement must leave the table '
                . 'behind exactly when that engine commits DDL as it runs. A table left standing '
                . 'on PostgreSQL or SQLite means SchemaManager did not wrap a batch it could have '
                . 'withdrawn; a table missing on MySQL means it believes something about that '
                . 'engine which is not true.',
                $driver->value,
            ),
        );
    }

    /**
     * Whether a `CREATE TABLE` outlives the failure of the statement after it.
     *
     * The engines' behaviour, written out rather than read back from the object under test.
     * MySQL commits `CREATE TABLE` implicitly, so the table is already permanent when the
     * `CREATE INDEX` behind it is rejected; PostgreSQL and SQLite both withdraw it with the
     * enclosing transaction. {@see TransactionalDdlContractTest} is where those three claims
     * are checked against real servers.
     */
    private function survivesAFailedBatch(Driver $driver): bool
    {
        return match ($driver) {
            Driver::MySQL => true,
            Driver::PostgreSQL, Driver::SQLite => false,
        };
    }

    private function engine(Driver $driver): ConnectionInterface
    {
        if (!DatabaseEngine::isConfigured($driver)) {
            self::markTestSkipped(DatabaseEngine::absenceReason($driver));
        }

        $connection = DatabaseEngine::connect($driver);
        $this->connection = $connection;
        $connection->execute('DROP TABLE IF EXISTS ' . self::TABLE);

        return $connection;
    }
}
