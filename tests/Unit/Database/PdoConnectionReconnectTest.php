<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\PdoConnection;
use Pulsar\Tests\Unit\Database\Support\FlakyPdoStatement;

use function file_exists;
use function filemtime;
use function gc_collect_cycles;
use function glob;
use function sys_get_temp_dir;
use function time;
use function uniqid;
use function unlink;

/**
 * The PDO handle used to be cached for the life of the object with nothing able to
 * clear it. Under a persistent runtime that is the life of the worker, so one dropped
 * connection — a server restart, a `wait_timeout`, a failover — made every subsequent
 * request served by that worker fail against a socket that was already gone.
 *
 * A file-backed SQLite database stands in for the server: it survives a reconnect the
 * way a real server does, so "the data is still there" proves the connection came back
 * rather than that nothing happened. {@see FlakyPdoStatement} supplies the failure,
 * through the real PDO call path.
 */
#[CoversClass(PdoConnection::class)]
final class PdoConnectionReconnectTest extends TestCase
{
    /** Age at which a leftover database file is another run's problem no longer. */
    private const int STALE_AFTER_SECONDS = 600;

    private string $databasePath;
    private ?PdoConnection $open = null;

    protected function setUp(): void
    {
        FlakyPdoStatement::reset();

        // Sweep anything a previous run could not delete (see tearDown), so the litter is
        // bounded rather than accumulating. Only files older than the cutoff: a second
        // suite running concurrently in the same temp directory has files seconds old,
        // and deleting one out from under it would fail that run instead of this one.
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_reconnect_*.sqlite') ?: [] as $stale) {
            if ((time() - (filemtime($stale) ?: time())) > self::STALE_AFTER_SECONDS) {
                @unlink($stale);
            }
        }

        $this->databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_reconnect_' . uniqid() . '.sqlite';
    }

    protected function tearDown(): void
    {
        FlakyPdoStatement::reset();

        $this->open?->disconnect();
        $this->open = null;
        gc_collect_cycles();

        // Best effort. A PDOException's stack trace keeps `$this` for every object frame
        // it passed through, including the PDOStatement that failed and therefore the
        // handle behind it, and PHPUnit holds test results past tearDown. On Windows
        // that is enough to keep the file locked a while longer. The sweep in setUp
        // finishes the job; failing a test over a temp file would say nothing about the
        // connection it was standing in for.
        if (file_exists($this->databasePath)) {
            @unlink($this->databasePath);
        }
    }

    /**
     * The blip must not outlive the request it happened in.
     *
     * `sqlite::memory:` is what makes the replacement observable: each PDO handle gets
     * its own database, so a statement that can no longer find the table it just
     * created is running on a handle that is not the one that created it. Before the
     * fix the dead handle was kept for the life of the object — the life of the worker,
     * under a persistent runtime — and this SELECT succeeded on it.
     */
    #[Test]
    public function aLostConnectionIsThrownAwayRatherThanKeptForTheNextRequest(): void
    {
        $connection = new PdoConnection(
            connectionName: 'reconnect-test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
            options: [PDO::ATTR_STATEMENT_CLASS => [FlakyPdoStatement::class, []]],
        );
        $connection->execute('CREATE TABLE ledger (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');

        FlakyPdoStatement::$failExecutes = 1;

        try {
            $connection->execute('INSERT INTO ledger (amount) VALUES (100)');
            self::fail('the simulated connection loss did not surface');
        } catch (DatabaseException) {
            // The statement may or may not have reached the server, so this one fails.
        }

        try {
            $connection->query('SELECT amount FROM ledger');
            self::fail('the dead handle was kept and served the next statement');
        } catch (DatabaseException $e) {
            self::assertStringContainsString(
                'no such table',
                $e->getPrevious()?->getMessage() ?? '',
                'the next statement failed, but not because it ran on a fresh connection',
            );
        }
    }

    /**
     * A prepare is a round trip that parses the SQL and executes none of it, so a
     * prepare that died with the connection changed nothing anywhere and can be
     * repeated on a fresh handle without the caller ever seeing the blip.
     */
    #[Test]
    public function aPrepareThatDiedWithTheConnectionIsRetriedOnce(): void
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE ledger (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');
        $connection->execute('INSERT INTO ledger (amount) VALUES (250)');

        FlakyPdoStatement::$failPrepares = 1;

        self::assertSame(
            250,
            $connection->query('SELECT amount FROM ledger')->firstOrFail()->getInt('amount'),
        );
    }

    /**
     * An execute that failed may have reached the server first. PDO cannot say which,
     * so re-sending it is not on the table: an INSERT that did arrive would be applied
     * twice. The failure reaches the caller; only the handle is thrown away.
     */
    #[Test]
    public function anExecuteThatDiedIsNotReSent(): void
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE ledger (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');

        FlakyPdoStatement::$failExecutes = 1;

        try {
            $connection->execute('INSERT INTO ledger (amount) VALUES (7)');
            self::fail('a statement that may already have been applied was re-sent');
        } catch (DatabaseException) {
            // Expected: the caller decides whether repeating it is safe.
        }

        self::assertSame(
            0,
            $connection->query('SELECT COUNT(*) AS c FROM ledger')->firstOrFail()->getInt('c'),
        );
    }

    /**
     * The server discarded the caller's uncommitted work along with the socket. A fresh
     * handle would run the next statement in autocommit, outside the transaction the
     * caller still believes surrounds it — so nothing is retried, and the depth counter
     * is reset so `inTransaction()` stops claiming a transaction that exists nowhere.
     */
    #[Test]
    public function aConnectionLostInsideATransactionEndsTheTransaction(): void
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE ledger (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');

        $connection->beginTransaction();
        self::assertTrue($connection->inTransaction());

        FlakyPdoStatement::$failExecutes = 1;

        try {
            $connection->execute('INSERT INTO ledger (amount) VALUES (1)');
            self::fail('the simulated connection loss did not surface');
        } catch (DatabaseException) {
            // Expected.
        }

        self::assertFalse(
            $connection->inTransaction(),
            'inTransaction() still claimed a transaction the lost socket took with it',
        );

        // And the next transaction issues BEGIN rather than SAVEPOINT against nothing.
        $transaction = $connection->beginTransaction();
        $connection->execute('INSERT INTO ledger (amount) VALUES (2)');
        $transaction->commit();

        self::assertSame(
            1,
            $connection->query('SELECT COUNT(*) AS c FROM ledger')->firstOrFail()->getInt('c'),
        );
    }

    /**
     * A failure that is not a lost connection leaves the handle alone. Reconnecting on
     * a deadlock or a constraint violation would drop a healthy connection on every
     * application bug, and would do it in the middle of the caller's transaction.
     */
    #[Test]
    public function anOrdinaryQueryFailureLeavesTheConnectionAndItsTransactionIntact(): void
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE ledger (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');

        $connection->beginTransaction();

        FlakyPdoStatement::$sqlState = '23000';
        FlakyPdoStatement::$driverCode = 1062;
        FlakyPdoStatement::$failExecutes = 1;

        try {
            $connection->execute('INSERT INTO ledger (amount) VALUES (1)');
            self::fail('the simulated failure did not surface');
        } catch (DatabaseException) {
            // Expected.
        }

        self::assertTrue(
            $connection->inTransaction(),
            'a duplicate-key error was mistaken for a lost connection and ended the transaction',
        );
    }

    private function connection(): PdoConnection
    {
        return $this->open = new PdoConnection(
            connectionName: 'reconnect-test',
            driver: Driver::SQLite,
            dsn: 'sqlite:' . $this->databasePath,
            username: null,
            password: null,
            options: [PDO::ATTR_STATEMENT_CLASS => [FlakyPdoStatement::class, []]],
        );
    }
}
