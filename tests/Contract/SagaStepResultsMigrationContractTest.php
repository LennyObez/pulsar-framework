<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Database\Driver;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Saga\Step\SagaStepDirection;
use Pulsar\Saga\Step\SagaStepResult;
use Pulsar\Saga\Step\SagaStepStatus;
use Pulsar\Tests\Support\FrameworkSchema;
use Pulsar\Workflow\Internal\Storage\DatabaseSagaStepResultStorage;
use RuntimeException;
use Throwable;

use function sprintf;
use function str_repeat;
use function strlen;

/**
 * `20260821000005_create_saga_step_results_table`, run as a file, against every engine.
 *
 * This table had no DDL anywhere in the repository — no installer, no migration, nothing —
 * while `SagaWiring` bound `DatabaseSagaStepResultStorage` unconditionally and
 * `SagaOrchestrator` called `record()`, `markCompleted()` and `updateStatus()` on it. Every
 * saga therefore failed at its first step on a table that had never been created, on every
 * engine, and no test noticed because no test ran the storage against a database.
 *
 * So the round-trip below is the primary assertion and the existence checks are the
 * scaffolding around it: this contract exists to prove that the four methods the
 * orchestrator actually calls work against the table this migration actually builds.
 */
final class SagaStepResultsMigrationContractTest extends SchemaMigrationContractTestCase
{
    private const string TABLE = 'saga_step_results';
    private const string INSTANCE_INDEX = 'idx_saga_step_results_instance';
    private const string IDEMPOTENCY_INDEX = 'idx_saga_step_results_idempotency';

    protected function tables(): array
    {
        return [self::TABLE];
    }

    protected function migrationPath(): string
    {
        return FrameworkSchema::SAGA_STEP_RESULTS;
    }

    #[Test]
    #[DataProvider('engines')]
    public function upCreatesTheTableItsColumnsAndBothIndexes(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->migration()->up($connection);

        self::assertTrue($this->tableExists($connection, self::TABLE));

        foreach ([
            'id',
            'instance_id',
            'step_name',
            'step_index',
            'direction',
            'status',
            'idempotency_key',
            'attempts',
            'result_data',
            'error_message',
            'started_at',
            'completed_at',
        ] as $column) {
            self::assertTrue($this->columnExists($connection, self::TABLE, $column), $column);
        }

        self::assertTrue($this->indexExists($connection, self::TABLE, self::INSTANCE_INDEX));
        self::assertTrue($this->indexExists($connection, self::TABLE, self::IDEMPOTENCY_INDEX));

        // The order of a composite key is the whole of it, and nothing else in this file can
        // see it: every index check the framework offers matches on the name, and both reads
        // return the same rows over any ordering — just by scanning.
        //
        // `getByInstance()` is `WHERE instance_id = ? ORDER BY step_index, started_at`, which
        // this key serves end to end in exactly this order. Lead with either sort column and
        // the equality predicate stops being a seek, which on the one table that grows with
        // every step of every saga is the difference the index exists to make.
        self::assertSame(
            ['instance_id', 'step_index', 'started_at'],
            $this->indexColumns($connection, self::TABLE, self::INSTANCE_INDEX),
        );

        self::assertSame(
            ['idempotency_key'],
            $this->indexColumns($connection, self::TABLE, self::IDEMPOTENCY_INDEX),
        );
    }

    /**
     * The idempotency index is narrowed to the rows that carry a key.
     *
     * Most step results have no idempotency key — only a step declared idempotent
     * records one — so an index over the whole column is mostly NULLs, and on the
     * table that grows with every step of every saga that is the difference between
     * a small index and a large one.
     *
     * Asserted here rather than assumed because a predicate leaves no trace an
     * existence check can see: an index built without it has the same name, the same
     * column and the same behaviour, only bigger. Deleting `where:` from the
     * migration left the whole suite green until this test existed.
     *
     * The two engines that keep the predicate spell it back differently — SQLite
     * verbatim from the statement it was given, PostgreSQL normalised by
     * `pg_get_expr()` — so what is asserted is what the predicate NAMES, not its
     * text. MySQL has no partial indexes, and asserting the predicate is null there
     * says the dialect really dropped it rather than emitting something the engine
     * would reject.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theIdempotencyIndexIsNarrowedToRowsThatCarryAKey(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->migration()->up($connection);

        $predicate = $this->indexPredicate($connection, self::TABLE, self::IDEMPOTENCY_INDEX);

        if (!$connection->dialect()->supportsPartialIndexes()) {
            self::assertNull(
                $predicate,
                'this engine has no partial indexes, so the dialect has to drop the predicate '
                . 'rather than compile something it would reject',
            );

            return;
        }

        self::assertNotNull(
            $predicate,
            'this engine has partial indexes and the index was built without one, so it '
            . 'covers every keyless row for nothing',
        );
        self::assertStringContainsString('idempotency_key', $predicate);
        self::assertMatchesRegularExpression('/NOT\s+NULL/i', $predicate);
    }

    /**
     * Re-runnable, and inert on the re-run.
     *
     * The seeded row is the part that matters. Existence checks alone are satisfied by a
     * migration whose `up()` opens with `DROP TABLE IF EXISTS`, and on this table that
     * mutant is worse than elsewhere: the rows record which steps already ran and under
     * which idempotency key, so a replay that clears them lets a resumed saga charge the
     * same card a second time.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aSecondUpLeavesTheTableItsIndexesAndItsRowsAlone(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->record($this->step('step-1', 0, SagaStepDirection::Forward, SagaStepStatus::Completed));

        $this->migration()->up($connection);

        self::assertTrue($this->indexExists($connection, self::TABLE, self::INSTANCE_INDEX));
        self::assertTrue($this->indexExists($connection, self::TABLE, self::IDEMPOTENCY_INDEX));

        self::assertCount(
            1,
            $storage->getByInstance('saga-1'),
            'a replayed up() must not clear the record of which steps already ran',
        );
        self::assertNotNull(
            $storage->findByIdempotencyKey('saga-1:charge:0'),
            'losing the idempotency key on a replay is what lets a resumed saga charge twice',
        );
    }

    #[Test]
    #[DataProvider('engines')]
    public function aRunInterruptedBeforeTheIndexesIsCompletedByTheNext(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $indexes = new IndexOperations($connection);
        $indexes->ensureAbsent(self::TABLE, self::INSTANCE_INDEX);
        $indexes->ensureAbsent(self::TABLE, self::IDEMPOTENCY_INDEX);

        self::assertTrue($this->tableExists($connection, self::TABLE), 'fixture precondition');

        $this->migration()->up($connection);

        self::assertTrue($this->indexExists($connection, self::TABLE, self::INSTANCE_INDEX));
        self::assertTrue($this->indexExists($connection, self::TABLE, self::IDEMPOTENCY_INDEX));
    }

    /**
     * The four calls `SagaOrchestrator` makes, in the order it makes them.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theOrchestratorsCallsAllWorkAgainstTheMigratedTable(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $storage = new DatabaseSagaStepResultStorage($connection);

        $storage->record(new SagaStepResult(
            id: 'step-1',
            instanceId: 'saga-1',
            stepName: 'charge',
            stepIndex: 0,
            direction: SagaStepDirection::Forward,
            status: SagaStepStatus::Running,
            idempotencyKey: 'saga-1:charge:0',
            attempts: 1,
            resultData: null,
            errorMessage: null,
            startedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            completedAt: null,
        ));

        $storage->updateStatus('step-1', SagaStepStatus::Failed, 'gateway timeout');
        $storage->incrementAttempts('step-1');
        $storage->markCompleted('step-1', ['charge_id' => 'ch-1']);

        $rows = $storage->getByInstance('saga-1');

        self::assertCount(1, $rows);
        self::assertSame(SagaStepStatus::Completed, $rows[0]->status);
        self::assertSame(['charge_id' => 'ch-1'], $rows[0]->resultData);
        self::assertSame(
            2,
            $rows[0]->attempts,
            'attempts = attempts + 1 yields NULL from NULL, which is why the column is NOT NULL '
            . 'with a default of 0',
        );
        self::assertNotNull($rows[0]->completedAt);

        self::assertNotNull(
            $storage->findByIdempotencyKey('saga-1:charge:0'),
            'the idempotency lookup is what stops a resumed saga charging twice',
        );
    }

    /**
     * The compensation record is the whole reason this table is not disposable: which
     * irreversible steps were skipped and which compensating actions completed exists here
     * and nowhere else.
     */
    #[Test]
    #[DataProvider('engines')]
    public function compensationRowsAreReadableByDirection(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $storage = new DatabaseSagaStepResultStorage($connection);

        $storage->record($this->step('step-1', 0, SagaStepDirection::Forward, SagaStepStatus::Completed));
        $storage->record($this->step('step-2', 0, SagaStepDirection::Compensating, SagaStepStatus::Skipped));

        $compensating = $storage->getByDirection('saga-1', SagaStepDirection::Compensating);

        self::assertCount(1, $compensating);
        self::assertSame('step-2', $compensating[0]->id);
        self::assertSame(SagaStepStatus::Skipped, $compensating[0]->status);
    }

    /**
     * Not unique, deliberately: a resumed saga recomputes the same key and records a second
     * row carrying it. A unique index here would turn a resume into a hard insert failure.
     */
    #[Test]
    #[DataProvider('engines')]
    public function twoRowsMayShareAnIdempotencyKey(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $storage = new DatabaseSagaStepResultStorage($connection);

        $storage->record($this->step('step-1', 0, SagaStepDirection::Forward, SagaStepStatus::Failed));
        $storage->record($this->step('step-2', 0, SagaStepDirection::Forward, SagaStepStatus::Completed));

        self::assertSame(2, $this->rowCount($connection, self::TABLE));
    }

    /**
     * `result_data` and `error_message` are sized by what a step produced, so both take the
     * wide text type.
     *
     * On MySQL that is `LONGTEXT` against the 65,535 *bytes* plain `Text` compiles to, and
     * the second column is the worse one to lose: `error_message` is written on the failure
     * path, so a value past the ceiling turns a step that failed into a `record()` that
     * also fails — under the shipped `sql_mode` error 1406, and with strict mode off a
     * truncated message, which is a diagnosis nobody can finish. `result_data` is what the
     * orchestrator reads back to decide whether a step needs re-running.
     *
     * **Only MySQL can fail this test.** PostgreSQL and SQLite give `Text` and `BigText` the
     * same `TEXT`, already the widest either has, so on those engines these assertions hold
     * for the unrepaired migration too and pin only that the compiled type is the expected
     * one.
     *
     * What the engine does with those types is a separate claim in a separate test — see
     * {@see aStepResultPastTheNarrowCeilingSurvivesWhole()}. Kept in one test, whichever
     * assertion ran first would be the only one a mutation ever reached.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theResultAndErrorColumnsCompileToTheWideTextType(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $this->assertWideText(
            $connection,
            self::TABLE,
            'result_data',
            'a step returns whatever it returns, and the orchestrator reads it back',
        );
        $this->assertWideText(
            $connection,
            self::TABLE,
            'error_message',
            'the failure path is the last place a write should be able to fail on width',
        );
    }

    /**
     * The same claim as the storage meets it.
     *
     * Both columns are written by a different statement — `markCompleted()` and
     * `updateStatus()` — so both are exercised rather than one standing in for the other.
     * Refused at INSERT and truncated on write are the two failures a MySQL `TEXT` column
     * produces, and reading the row back separates both from success.
     *
     * **Only MySQL can fail this test**, for the reason its catalogue sibling gives.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aStepResultPastTheNarrowCeilingSurvivesWhole(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $blob = str_repeat('r', 70_000);

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->record($this->step('step-1', 0, SagaStepDirection::Forward, SagaStepStatus::Running));

        try {
            $storage->updateStatus('step-1', SagaStepStatus::Failed, $blob);
            $storage->markCompleted('step-1', ['receipt' => $blob]);
        } catch (Throwable $e) {
            self::fail(sprintf(
                'writing %d bytes into a step result was refused, which is what MySQL does to a '
                . 'TEXT column under the shipped sql_mode: %s',
                strlen($blob),
                $e->getMessage(),
            ));
        }

        $rows = $storage->getByInstance('saga-1');

        self::assertCount(1, $rows);
        self::assertSame(
            ['receipt' => $blob],
            $rows[0]->resultData,
            'truncated result data is a step the orchestrator cannot tell it already ran',
        );

        // The error message survives the completion that follows it: `markCompleted()`
        // leaves the column alone, so the diagnosis of the failed attempt is still readable
        // beside the eventual success.
        self::assertSame(
            $blob,
            $rows[0]->errorMessage,
            'a truncated error message is a failure nobody can finish diagnosing',
        );
    }

    /**
     * The table is declared to compare text byte for byte.
     *
     * Four of the storage's seven statements select on an identifier — `id` for the three
     * updates, `idempotency_key` for the lookup that decides whether a step already ran —
     * so a case-insensitive comparison here is not a sorting quirk. Under MySQL 8's default
     * `utf8mb4_0900_ai_ci` `record()` refuses the second row as a duplicate key, and
     * `markCompleted()` on one id stamps whichever row the engine reaches. The table exists
     * to record which steps ran under which key; a key that folds two ids together is a
     * resumed saga charging a card twice.
     * {@see \Pulsar\Database\Schema\SchemaCollation::Exact} is what stops it.
     *
     * Which engines can fail this is set out at
     * {@see SchemaMigrationContractTestCase::assertExactCollation()}: MySQL is the only one
     * with a clause to read back. The behaviour that clause buys is asserted separately, in
     * {@see twoStepIdsDifferingOnlyInCaseAreTwoStepRecords()}, so that neither half can
     * shadow the other when the repair is mutated away.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theTableIsCollatedForByteExactComparison(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $this->assertExactCollation($connection, self::TABLE, [
            'id',
            'instance_id',
            'step_name',
            'direction',
            'status',
            'idempotency_key',
            'result_data',
            'error_message',
        ]);
    }

    /**
     * Two step-result ids differing only in case are two step records.
     *
     * The catalogue's claim, made of the engine, and asserted on all three: PostgreSQL and
     * SQLite compare text byte-wise by default, so here their guarantee is asserted as
     * theirs rather than as this migration's.
     */
    #[Test]
    #[DataProvider('engines')]
    public function twoStepIdsDifferingOnlyInCaseAreTwoStepRecords(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $storage = new DatabaseSagaStepResultStorage($connection);

        $this->assertCaseVariantKeysCoexist(
            $connection,
            self::TABLE,
            'id',
            static function (string $id) use ($storage): void {
                $storage->record(new SagaStepResult(
                    id: $id,
                    instanceId: 'saga-1',
                    stepName: 'charge',
                    stepIndex: 0,
                    direction: SagaStepDirection::Forward,
                    status: SagaStepStatus::Running,
                    idempotencyKey: 'key-' . $id,
                    attempts: 1,
                    resultData: null,
                    errorMessage: null,
                    startedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
                    completedAt: null,
                ));
            },
        );

        // The update path, which is where a folded key does its damage: a stamp addressed
        // to one step must not land on its case twin.
        $storage->markCompleted('AB', ['charged' => true]);

        self::assertSame(
            ['charged' => true],
            $storage->findByIdempotencyKey('key-AB')?->resultData,
        );
        self::assertNull(
            $storage->findByIdempotencyKey('key-ab')?->resultData,
            'the other step never completed, and a stamp that reached it would say it had',
        );
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

    #[Test]
    #[DataProvider('engines')]
    public function downRefusesToDropTheCompensationRecord(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $migration = $this->migration();
        $migration->up($connection);

        new DatabaseSagaStepResultStorage($connection)
            ->record($this->step('step-1', 0, SagaStepDirection::Compensating, SagaStepStatus::Completed));

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

    private function step(
        string $id,
        int $index,
        SagaStepDirection $direction,
        SagaStepStatus $status,
    ): SagaStepResult {
        return new SagaStepResult(
            id: $id,
            instanceId: 'saga-1',
            stepName: 'charge',
            stepIndex: $index,
            direction: $direction,
            status: $status,
            idempotencyKey: 'saga-1:charge:' . $index,
            attempts: 1,
            resultData: null,
            errorMessage: null,
            startedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            completedAt: null,
        );
    }
}
