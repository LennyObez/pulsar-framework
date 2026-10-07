<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationRepository;
use Pulsar\Database\Migration\MigrationRunner;
use Throwable;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

/**
 * The migration runner, driven as an operator drives it, against every engine.
 *
 * Every other migration test in this suite calls `up()` directly. That is why none of them
 * could see the defect this class exists to pin: `runPending()` wraps each `up()` in
 * `$connection->transaction()`, and MySQL commits implicitly at every DDL statement — so
 * by the time the wrapper committed there was no transaction, `PDO::commit()` raised
 * "There is no active transaction", the catch called `rollback()` which raised the same,
 * and **that** exception left the method in place of the original.
 *
 * The consequence was total: the DDL landed, `recordMigration()` was never reached, the
 * tracking table stayed empty, and every later `migrate` re-derived the same pending list
 * and died identically. A migration whose entire body was `CREATE TABLE IF NOT EXISTS
 * probe (id INT)` failed the same way, so no DDL migration could be applied on MySQL at
 * all. Calling `up()` directly stepped over the wrapper and reported success.
 */
final class MigrationRunnerContractTest extends TestCase
{
    private ?ConnectionInterface $connection = null;
    private string $migrationsDir = '';
    private string $trackingTable = '';

    /** @var list<string> */
    private array $createdTables = [];

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            foreach ([...$this->createdTables, $this->trackingTable] as $table) {
                if ($table !== '') {
                    $this->connection->execute(sprintf('DROP TABLE IF EXISTS %s', $table));
                }
            }
        }

        $this->removeDirectory($this->migrationsDir);

        $this->connection = null;
        $this->migrationsDir = '';
        $this->trackingTable = '';
        $this->createdTables = [];
    }

    /**
     * @return iterable<string, array{Driver}>
     */
    public static function engines(): iterable
    {
        foreach (DatabaseEngine::all() as $driver) {
            yield $driver->value => [$driver];
        }
    }

    /**
     * The whole defect in one assertion: DDL applied, and the runner knows it.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aDdlMigrationIsAppliedAndRecorded(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $table = $this->writeCreateTableMigration('20990101000001', 'probe_one');

        $runner = $this->runner($connection);
        $applied = $runner->runPending();

        self::assertSame(['20990101000001'], $applied);
        self::assertTrue($this->tableExists($connection, $driver, $table), 'the DDL must have landed');
        self::assertCount(
            1,
            $runner->getApplied(),
            'the runner must record what it applied, or every later run repeats it and fails the same way',
        );
    }

    #[Test]
    #[DataProvider('engines')]
    public function asecondRunHasNothingLeftToDo(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->writeCreateTableMigration('20990101000002', 'probe_two');

        $runner = $this->runner($connection);
        $runner->runPending();

        self::assertSame(
            [],
            $runner->runPending(),
            'an unrecorded migration re-derives as pending forever',
        );
    }

    /**
     * A migration that fails must surface its own failure.
     *
     * The rollback that follows may add context and must never replace it. An operator
     * handed "There is no active transaction" learns nothing about why their migration
     * stopped, and the message names a savepoint they never wrote.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aFailingMigrationSurfacesItsOwnCause(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->writeThrowingMigration('20990101000003');

        $caught = null;

        try {
            $this->runner($connection)->runPending();
        } catch (Throwable $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'a throwing migration must not be reported as applied');

        $chain = [];

        for ($e = $caught; $e !== null; $e = $e->getPrevious()) {
            $chain[] = $e->getMessage();
        }

        self::assertNotSame([], array_filter(
            $chain,
            static fn(string $m): bool => str_contains($m, 'deliberate probe failure'),
        ), 'the migration\'s own cause must survive somewhere in the chain, got: ' . implode(' <- ', $chain));
    }

    /**
     * After a failure the connection must still be usable.
     *
     * `Transaction::commit()` used to throw before clearing its active flag, so the
     * connection's depth counter never unwound: every later transaction issued SAVEPOINT
     * instead of BEGIN and none of them could be committed. One failed migration poisoned
     * the connection for the rest of its life.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aFailedRunLeavesTheConnectionUsable(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->writeThrowingMigration('20990101000004');

        try {
            $this->runner($connection)->runPending();
        } catch (Throwable) {
            // Expected; the point is what the connection can do afterwards.
        }

        self::assertSame(
            42,
            $connection->transaction(static fn(): int => 42),
            'the transaction depth must have unwound, or every later transaction is a savepoint that cannot commit',
        );
    }

    // --- fixture ---

    private function runner(ConnectionInterface $connection): MigrationRunner
    {
        return new MigrationRunner(
            $connection,
            new MigrationRepository($this->migrationsDir),
            $this->trackingTable,
        );
    }

    /**
     * @return string The table the migration creates
     */
    private function writeCreateTableMigration(string $version, string $suffix): string
    {
        $table = 'probe_' . $suffix . '_' . bin2hex(random_bytes(3));
        $this->createdTables[] = $table;

        file_put_contents(
            $this->migrationsDir . '/' . $version . '_create_' . $suffix . '.php',
            <<<PHP
                <?php

                declare(strict_types=1);

                use Pulsar\\Database\\ConnectionInterface;
                use Pulsar\\Database\\Migration\\MigrationInterface;

                return new class implements MigrationInterface {
                    public function up(ConnectionInterface \$connection): void
                    {
                        \$connection->execute('CREATE TABLE IF NOT EXISTS {$table} (id INT NOT NULL)');
                    }

                    public function down(ConnectionInterface \$connection): void
                    {
                        \$connection->execute('DROP TABLE IF EXISTS {$table}');
                    }
                };
                PHP,
        );

        return $table;
    }

    /**
     * The DDL statement before the throw is the whole point of this fixture.
     *
     * Without it the migration failed before touching the database, so the transaction
     * was still open, the rollback succeeded, and neither repaired branch of
     * {@see \Pulsar\Database\Transaction} was ever entered — on any engine. Mutation
     * testing proved it: reverting the rollback-attach, and deleting both `finally`
     * blocks, each left this suite and 2,218 other tests entirely green.
     *
     * With the statement, MySQL has implicitly committed by the time the throw lands, so
     * the rollback meets the state the repair exists for.
     */
    private function writeThrowingMigration(string $version): void
    {
        $table = 'probe_partial_' . bin2hex(random_bytes(3));
        $this->createdTables[] = $table;

        file_put_contents(
            $this->migrationsDir . '/' . $version . '_throws.php',
            <<<PHP
                <?php

                declare(strict_types=1);

                use Pulsar\\Database\\ConnectionInterface;
                use Pulsar\\Database\\Migration\\MigrationInterface;

                return new class implements MigrationInterface {
                    public function up(ConnectionInterface \$connection): void
                    {
                        \$connection->execute('CREATE TABLE IF NOT EXISTS {$table} (id INT NOT NULL)');

                        throw new RuntimeException('deliberate probe failure');
                    }

                    public function down(ConnectionInterface \$connection): void {}
                };
                PHP,
        );
    }

    private function tableExists(ConnectionInterface $connection, Driver $driver, string $table): bool
    {
        $sql = match ($driver) {
            Driver::SQLite => "SELECT COUNT(*) AS c FROM sqlite_master WHERE type = 'table' AND name = :t",
            Driver::MySQL => 'SELECT COUNT(*) AS c FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name = :t',
            Driver::PostgreSQL => 'SELECT COUNT(*) AS c FROM pg_class WHERE oid = to_regclass(:t)',
        };

        foreach ($connection->query($sql, ['t' => $table])->rows as $row) {
            return $row->getInt('c') > 0;
        }

        return false;
    }

    private function engine(Driver $driver): ConnectionInterface
    {
        if (!DatabaseEngine::isConfigured($driver)) {
            self::markTestSkipped(DatabaseEngine::absenceReason($driver));
        }

        $this->connection = DatabaseEngine::connect($driver);

        $suffix = bin2hex(random_bytes(4));
        $this->trackingTable = 'probe_migrations_' . $suffix;
        $this->migrationsDir = sys_get_temp_dir() . '/pulsar_runner_' . $suffix;
        mkdir($this->migrationsDir, 0o750, true);

        return $this->connection;
    }

    private function removeDirectory(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                unlink($dir . '/' . $item);
            }
        }

        rmdir($dir);
    }
}
