<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Database\Driver;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Queue\Driver\DatabaseFailedJobRepository;
use Pulsar\Queue\FailedJob;
use Pulsar\Tests\Support\FrameworkSchema;
use RuntimeException;
use Throwable;

use function sprintf;
use function str_repeat;
use function strlen;

/**
 * `20260821000003_create_failed_jobs_table`, run as a file, against every engine.
 *
 * `failed_jobs` holds dead-lettered work: the exception, the attempt count and the payload
 * an operator inspects, retries or purges under a retention policy. It is a retention
 * artefact, so the two things this contract is most concerned with are that the table is
 * actually there before a worker needs it — the old installer had no production caller —
 * and that a rollback cannot quietly delete the record.
 */
final class FailedJobsMigrationContractTest extends SchemaMigrationContractTestCase
{
    private const string TABLE = 'failed_jobs';
    private const string INDEX = 'failed_jobs_failed_at_idx';

    protected function tables(): array
    {
        return [self::TABLE];
    }

    protected function migrationPath(): string
    {
        return FrameworkSchema::FAILED_JOBS;
    }

    #[Test]
    #[DataProvider('engines')]
    public function upCreatesTheTableAndTheFailedAtIndex(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->migration()->up($connection);

        self::assertTrue($this->tableExists($connection, self::TABLE));

        foreach (['id', 'queue', 'job_class', 'payload', 'exception', 'failed_at', 'attempts'] as $column) {
            self::assertTrue($this->columnExists($connection, self::TABLE, $column), $column);
        }

        self::assertTrue(
            $this->indexExists($connection, self::TABLE, self::INDEX),
            'all() orders by failed_at and retention pruning selects on it',
        );

        // The name is not the index. `IndexOperations` creates and finds indexes by name
        // alone, so an index built over the wrong column answers every existence check the
        // framework can make while leaving both queries above on a full scan. The column is
        // the claim, and it is read back from the catalogue.
        self::assertSame(
            ['failed_at'],
            $this->indexColumns($connection, self::TABLE, self::INDEX),
            'an index over any other column leaves the retention sweep scanning the table',
        );
    }

    /**
     * Re-runnable, and inert on the re-run.
     *
     * A replayed `up()` — the ordinary consequence of a run that died before the runner
     * could record it — must find the table already there and do nothing to it. Asserting
     * only that the table and index survive is not enough to show that: a migration whose
     * `up()` opened with `DROP TABLE IF EXISTS` passes such a check on every engine while
     * deleting the dead-letter record on every replay. The stored job is what makes the
     * difference observable.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aSecondUpLeavesTheTableItsIndexAndItsRowsAlone(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $repository = new DatabaseFailedJobRepository($connection);
        $repository->store(new FailedJob(
            id: 'job-survives-the-replay',
            queue: 'default',
            jobClass: 'App\\Job\\ChargeCard',
            payload: '{"amount":100}',
            exception: 'RuntimeException: gateway timeout',
            failedAt: 1_767_268_800,
            attempts: 3,
        ));

        $this->migration()->up($connection);

        self::assertTrue($this->indexExists($connection, self::TABLE, self::INDEX));
        self::assertSame(
            1,
            $repository->count(),
            'a replayed up() must not purge the dead-letter record it finds already there',
        );
        self::assertNotNull(
            $repository->find('job-survives-the-replay'),
            'the surviving row must still be one the repository can read back',
        );
    }

    /**
     * The index is created outside the table guard, so a run that died between the two
     * finishes the job on its next pass instead of reporting success without an index.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aRunInterruptedBeforeTheIndexIsCompletedByTheNext(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        new IndexOperations($connection)->ensureAbsent(self::TABLE, self::INDEX);
        self::assertFalse($this->indexExists($connection, self::TABLE, self::INDEX), 'fixture precondition');

        $this->migration()->up($connection);

        self::assertTrue($this->indexExists($connection, self::TABLE, self::INDEX));
    }

    /**
     * The upsert `store()` issues is keyed on `id`, so a redelivered poison job overwrites
     * rather than duplicating — which is only true if the engine enforces the key.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theRepositoryRoundTripsAndAReplayedJobOverwrites(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $repository = new DatabaseFailedJobRepository($connection);

        $repository->store(new FailedJob(
            id: 'job-1',
            queue: 'default',
            jobClass: 'App\\Job\\ChargeCard',
            payload: '{"amount":100}',
            exception: 'RuntimeException: gateway timeout',
            failedAt: 1_767_268_800,
            attempts: 3,
        ));

        $repository->store(new FailedJob(
            id: 'job-1',
            queue: 'default',
            jobClass: 'App\\Job\\ChargeCard',
            payload: '{"amount":100}',
            exception: 'RuntimeException: gateway timeout',
            failedAt: 1_767_268_900,
            attempts: 4,
        ));

        $found = $repository->find('job-1');

        self::assertNotNull($found);
        self::assertSame(4, $found->attempts, 'the second store must update the row, not add one');
        self::assertSame(1_767_268_900, $found->failedAt);
        self::assertSame(1, $repository->count());
    }

    /**
     * `payload` and `exception` are the two columns whose width the data decides, and on
     * MySQL that is a decision the schema has to make explicitly.
     *
     * The migration asks for {@see \Pulsar\Database\Schema\SchemaColumnType::BigText},
     * which is `LONGTEXT` there and 4 GiB; plain `Text` would be `TEXT` and 65,535 *bytes*.
     * A serialized-then-encrypted job payload or a deep stack trace passes 64 KiB without
     * being unusual, and past it the shipped `sql_mode` raises error 1406 — so the write
     * that fails is the one recording a failure, and the dead-letter record whose entire
     * purpose is to outlive the job is the thing that is lost.
     *
     * **Only MySQL can fail this test.** PostgreSQL and SQLite compile both text types to
     * `TEXT`, which is already the widest either engine has, so on those two engines every
     * assertion below is true of the unrepaired migration as well. They are still run
     * there: what they pin is that the compiled type is the one the migration expects,
     * which is the claim the two engines can make.
     *
     * `failed_jobs` has no deliberately-narrow text column to contrast against — every
     * text column here is a payload or an identifier. The contrast is drawn where the
     * migration actually draws a line, in {@see EventOutboxMigrationContractTest} and
     * {@see WorkflowTablesMigrationContractTest}, which each keep one column narrow on
     * purpose and assert that too.
     *
     * The engine's behaviour is the separate claim and lives in a separate test — see
     * {@see aDeadLetterRecordPastTheNarrowCeilingSurvivesWhole()}. Kept together, whichever
     * assertion ran first would be the only one a mutation ever reached, and the other
     * would be decoration that no experiment could distinguish from an empty line.
     */
    #[Test]
    #[DataProvider('engines')]
    public function thePayloadColumnsCompileToTheWideTextType(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $this->assertWideText(
            $connection,
            self::TABLE,
            'payload',
            'an encrypted job payload is sized by the job, not by a form',
        );

        $this->assertWideText(
            $connection,
            self::TABLE,
            'exception',
            'a stack trace from a deep failure is the same kind of unbounded',
        );
    }

    /**
     * The same claim, asked of the engine instead of its catalogue.
     *
     * `DATA_TYPE` saying `longtext` and a server accepting a 70,000-byte record are not the
     * same fact, and it is the second one the dead-letter path depends on. This is where a
     * narrow column is caught doing either of the two things it does on MySQL: refusing the
     * write with error 1406 under the shipped `sql_mode`, or — with strict mode switched
     * off — truncating the value and reporting success, which is the same defect wearing a
     * quieter face. Reading the record back separates both from success.
     *
     * **Only MySQL can fail this test**, for the reason its catalogue sibling gives: on
     * PostgreSQL and SQLite `Text` and `BigText` are the same `TEXT` and neither engine has
     * a ceiling to reach here.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aDeadLetterRecordPastTheNarrowCeilingSurvivesWhole(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $payload = str_repeat('p', 70_000);
        $exception = str_repeat('e', 70_000);

        $repository = new DatabaseFailedJobRepository($connection);

        try {
            $repository->store(new FailedJob(
                id: 'job-oversized',
                queue: 'default',
                jobClass: 'App\\Job\\ChargeCard',
                payload: $payload,
                exception: $exception,
                failedAt: 1_767_268_800,
                attempts: 5,
            ));
        } catch (Throwable $e) {
            self::fail(sprintf(
                'storing a %d-byte payload was refused, which is what a TEXT column does on '
                . 'MySQL under the shipped sql_mode: %s',
                strlen($payload),
                $e->getMessage(),
            ));
        }

        $found = $repository->find('job-oversized');

        self::assertNotNull($found);
        self::assertSame($payload, $found->payload, 'a truncated payload is a dead-letter record nobody can retry');
        self::assertSame($exception, $found->exception, 'a truncated trace is a failure nobody can diagnose');
    }

    /**
     * The table is declared to compare text byte for byte.
     *
     * `store()` upserts on the `id` primary key, so this is the collation with teeth rather
     * than a sorting preference: under MySQL 8's default `utf8mb4_0900_ai_ci` two ids
     * differing only in case compare equal, the second `store()` becomes an
     * `ON DUPLICATE KEY UPDATE` over the first, and one audit record is gone with no error
     * anywhere to say so. The migration asks for
     * {@see \Pulsar\Database\Schema\SchemaCollation::Exact} for exactly that reason. The
     * framework's own drivers mint lowercase hex ids that cannot collide this way; a queue
     * driver supplying its own is not held to that, which is why the guarantee belongs to
     * the schema rather than to an id generator.
     *
     * Which engines can fail this is set out at
     * {@see SchemaMigrationContractTestCase::assertExactCollation()}: MySQL is the only one
     * with a clause to read back. What the other two guarantee is asserted as behaviour, in
     * {@see twoJobIdsDifferingOnlyInCaseAreTwoDeadLetterRecords()} — a separate test so that
     * each half can be shown to be load-bearing on its own rather than shadowed by whichever
     * assertion happened to run first.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theTableIsCollatedForByteExactComparison(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $this->assertExactCollation($connection, self::TABLE, ['id', 'queue', 'job_class', 'payload', 'exception']);
    }

    /**
     * Two dead-lettered jobs whose ids differ only in case are two records.
     *
     * The claim its catalogue sibling makes, made of the engine instead: a `TABLE_COLLATION`
     * row saying `utf8mb4_bin` and a server that refuses to fold `AB` into `ab` are not the
     * same fact, and it is the second one the audit record depends on. This assertion holds
     * on all three engines — PostgreSQL and SQLite compare text byte-wise by default, so
     * here their guarantee is asserted as theirs rather than as the migration's.
     */
    #[Test]
    #[DataProvider('engines')]
    public function twoJobIdsDifferingOnlyInCaseAreTwoDeadLetterRecords(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $repository = new DatabaseFailedJobRepository($connection);

        $this->assertCaseVariantKeysCoexist(
            $connection,
            self::TABLE,
            'id',
            static function (string $id) use ($repository): void {
                $repository->store(new FailedJob(
                    id: $id,
                    queue: 'queue-' . $id,
                    jobClass: 'App\\Job\\ChargeCard',
                    payload: '{}',
                    exception: 'RuntimeException: boom',
                    failedAt: 1_767_268_800,
                    attempts: 1,
                ));
            },
        );

        // Coexisting is half of it. The other half is that a lookup by id reaches the row
        // it named: `find()` matches `WHERE id = :id`, and a case-insensitive column would
        // hand back whichever of the two the engine happened to reach first.
        self::assertSame('queue-AB', $repository->find('AB')?->queue);
        self::assertSame('queue-ab', $repository->find('ab')?->queue);
    }

    #[Test]
    #[DataProvider('engines')]
    public function downDropsAnEmptyTable(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $migration = $this->migration();
        $migration->up($connection);
        $migration->down($connection);

        self::assertFalse($this->tableExists($connection, self::TABLE));
    }

    /**
     * A rollback that empties the dead-letter table is not a reversal, it is the deletion
     * of the audit records the table exists to keep — performed by a command an operator
     * reaches for expecting the opposite.
     */
    #[Test]
    #[DataProvider('engines')]
    public function downRefusesToDropDeadLetterRecords(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $migration = $this->migration();
        $migration->up($connection);

        new DatabaseFailedJobRepository($connection)->store(new FailedJob(
            id: 'job-1',
            queue: 'default',
            jobClass: 'App\\Job\\ChargeCard',
            payload: '{}',
            exception: 'RuntimeException: boom',
            failedAt: 1_767_268_800,
            attempts: 1,
        ));

        $refused = null;

        try {
            $migration->down($connection);
        } catch (RuntimeException $e) {
            $refused = $e;
        }

        self::assertNotNull($refused);
        self::assertStringContainsString(self::TABLE, $refused->getMessage());
        self::assertTrue($this->tableExists($connection, self::TABLE));
        self::assertSame(1, $this->rowCount($connection, self::TABLE));
    }

    #[Test]
    #[DataProvider('engines')]
    public function downOnAnAbsentTableIsANoOp(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->migration()->down($connection);

        self::assertFalse($this->tableExists($connection, self::TABLE));
    }
}
