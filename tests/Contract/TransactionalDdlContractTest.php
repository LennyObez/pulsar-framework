<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\TableIntrospector;

use function sprintf;

/**
 * Asks each engine what it does with DDL inside a transaction, and holds
 * {@see SchemaCapabilities::supportsTransactionalDdl()} to the answer.
 *
 * That method used to say SQLite has no transactional DDL. It does. The claim had never
 * been executed — every test of it compared the method's return value to a value written
 * beside it, which proves the `match` arm is spelled the way somebody spelled it and
 * nothing else — and five migrations then wrote their idempotency arguments on top of the
 * mistake, each explaining that a resumed run on SQLite would meet a half-built schema it
 * cannot in fact meet.
 *
 * A capability is a claim about an engine, so only the engine can settle it. Every
 * assertion below is made by a server: the framework states what it believes, the test
 * performs the experiment, and the two must agree. Flip an arm of that `match` and the
 * engine that contradicts it fails here.
 *
 * ## Why this is a contract test and not a unit test
 *
 * SQLite runs everywhere, so its half of this is enforced on every run. MySQL and
 * PostgreSQL need a server, and {@see DatabaseEngine} draws the line that matters:
 * an engine nobody configured skips with a reason, while an engine that was configured
 * and cannot be reached fails. A run with a dead MySQL must not look like a run with no
 * MySQL, because MySQL is the engine whose answer here is the unusual one.
 */
final class TransactionalDdlContractTest extends TestCase
{
    private const string TABLE = 'txn_ddl_probe';

    private const string WITNESS = 'txn_ddl_witness';

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
        $this->dropProbeTables();
        $this->connection = null;
    }

    /**
     * A `CREATE TABLE` inside a transaction: gone on rollback, or already permanent.
     *
     * The experiment the capability names, run in the shape the capability is asked about.
     * The framework's belief is read first and the engine is asked second, so the failure
     * message can say which of the two was wrong rather than merely that they differ.
     *
     * On an engine that ended the transaction by itself, the rollback is not silently
     * skipped — {@see \Pulsar\Database\Transaction::rollback()} raises rather than
     * reporting a withdrawal that did not happen, and that raise is part of the contract:
     * it is how a caller learns its work is permanent.
     */
    #[Test]
    #[DataProvider('engines')]
    public function createTableInsideATransactionSurvivesRollbackOnlyWhereDdlIsNotTransactional(
        Driver $driver,
    ): void {
        $connection = $this->engine($driver);
        $believesItIsTransactional = $this->capabilities($connection)->supportsTransactionalDdl();

        $transaction = $connection->beginTransaction();
        $connection->execute(sprintf('CREATE TABLE %s (id INTEGER)', self::TABLE));

        $rollbackWasPossible = true;

        try {
            $transaction->rollback();
        } catch (DatabaseException) {
            // The engine committed on its own, so there is nothing left to withdraw.
            $rollbackWasPossible = false;
        }

        self::assertSame(
            $believesItIsTransactional,
            $rollbackWasPossible,
            sprintf(
                'SchemaCapabilities says %s %s transactional DDL, so a ROLLBACK after a CREATE '
                . 'TABLE should%s have been possible.',
                $driver->value,
                $believesItIsTransactional ? 'has' : 'has no',
                $believesItIsTransactional ? '' : ' not',
            ),
        );

        self::assertSame(
            !$believesItIsTransactional,
            $this->tableExists($connection, self::TABLE),
            sprintf(
                'A CREATE TABLE rolled back on %s must survive exactly when that engine commits '
                . 'DDL implicitly. It did the opposite, so supportsTransactionalDdl() describes '
                . 'an engine other than the one that answered.',
                $driver->value,
            ),
        );
    }

    /**
     * Rows written before the DDL: carried out with it, or committed by it.
     *
     * The half that decides whether a caller may put a schema change and a data change in
     * one transaction, and the half a `CREATE TABLE` probe alone cannot see. Where DDL
     * commits implicitly it commits everything before it too, so an INSERT the caller
     * still intended to be able to withdraw is already permanent — the failure mode is
     * data that outlives the transaction that wrote it, not a stray table.
     */
    #[Test]
    #[DataProvider('engines')]
    public function ddlCommitsTheRowsWrittenBeforeItOnlyWhereDdlIsNotTransactional(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $believesItIsTransactional = $this->capabilities($connection)->supportsTransactionalDdl();

        $connection->execute(sprintf('CREATE TABLE %s (id INTEGER)', self::WITNESS));

        $transaction = $connection->beginTransaction();
        $connection->execute(sprintf('INSERT INTO %s (id) VALUES (1)', self::WITNESS));
        $connection->execute(sprintf('CREATE TABLE %s (id INTEGER)', self::TABLE));

        try {
            $transaction->rollback();
        } catch (DatabaseException) {
            // Same engine-side commit as above; the point here is what it took with it.
        }

        self::assertSame(
            $believesItIsTransactional ? 0 : 1,
            $this->rowCount($connection, self::WITNESS),
            sprintf(
                'On %s a DDL statement issued after an INSERT must commit that INSERT exactly '
                . 'when the engine has no transactional DDL. A surviving row on an engine that '
                . 'claims transactional DDL means a caller can lose the ability to withdraw a '
                . 'write by putting a schema change behind it.',
                $driver->value,
            ),
        );
    }

    /**
     * `ALTER TABLE ... ADD COLUMN` and `CREATE INDEX`, which are the statements the
     * framework's own migrations actually issue.
     *
     * `CREATE TABLE` is not the whole list, and an engine is free to treat the rest
     * differently — MySQL's implicit-commit list is enumerated statement by statement
     * rather than derived from a category, and temporary-table DDL sits outside it. The
     * framework's own migrations create tables, add a column and create indexes, so all
     * three are measured rather than assumed to follow the first.
     */
    #[Test]
    #[DataProvider('engines')]
    public function alterAndCreateIndexFollowTheSameRuleAsCreateTable(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $believesItIsTransactional = $this->capabilities($connection)->supportsTransactionalDdl();

        $connection->execute(sprintf('CREATE TABLE %s (id INTEGER)', self::TABLE));

        $transaction = $connection->beginTransaction();
        $connection->execute(sprintf('ALTER TABLE %s ADD COLUMN added INTEGER', self::TABLE));
        $connection->execute(sprintf('CREATE INDEX %s_idx ON %s (id)', self::TABLE, self::TABLE));

        try {
            $transaction->rollback();
        } catch (DatabaseException) {
            // As above.
        }

        $introspector = new TableIntrospector($connection);

        self::assertSame(
            !$believesItIsTransactional,
            $introspector->columnExists(self::TABLE, 'added'),
            sprintf('ALTER TABLE ... ADD COLUMN must roll back on %s exactly when DDL is '
                . 'transactional there', $driver->value),
        );

        self::assertSame(
            !$believesItIsTransactional,
            new IndexOperations($connection)->exists(self::TABLE, self::TABLE . '_idx'),
            sprintf('CREATE INDEX must roll back on %s exactly when DDL is transactional there', $driver->value),
        );
    }

    /**
     * Connect, and start from a database without the probe tables.
     *
     * A previous run that died between the `CREATE` and the drop would otherwise hand the
     * next one a table it did not create, and every assertion here is about whether a
     * table is present.
     */
    private function engine(Driver $driver): ConnectionInterface
    {
        if (!DatabaseEngine::isConfigured($driver)) {
            self::markTestSkipped(DatabaseEngine::absenceReason($driver));
        }

        $connection = DatabaseEngine::connect($driver);
        $this->connection = $connection;
        $this->dropProbeTables();

        return $connection;
    }

    /**
     * The capabilities the framework would build for this connection, variant included —
     * the same object the migrations construct, so the belief under test is the deployed
     * one rather than a default.
     */
    private function capabilities(ConnectionInterface $connection): SchemaCapabilities
    {
        return new SchemaCapabilities($connection->driver(), $connection, $connection->variant());
    }

    private function tableExists(ConnectionInterface $connection, string $table): bool
    {
        return new TableIntrospector($connection)->tableExists($table);
    }

    private function rowCount(ConnectionInterface $connection, string $table): int
    {
        foreach ($connection->query('SELECT COUNT(*) AS c FROM ' . $table)->rows as $row) {
            return $row->getInt('c');
        }

        return 0;
    }

    /**
     * Dropped outside any transaction the test may have left open, and each in its own
     * statement, so one absent table cannot stop the other from being cleaned up.
     */
    private function dropProbeTables(): void
    {
        if ($this->connection === null) {
            return;
        }

        foreach ([self::TABLE, self::WITNESS] as $table) {
            $this->connection->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
