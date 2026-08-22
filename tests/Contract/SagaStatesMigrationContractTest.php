<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Saga\SagaState;
use Pulsar\Saga\SagaStatus;
use Pulsar\Saga\Step\StepResult;
use Pulsar\Tests\Support\FrameworkSchema;
use Pulsar\Workflow\Internal\Storage\DatabaseSagaStateStorage;
use RuntimeException;
use Throwable;

use function sprintf;
use function str_repeat;
use function strlen;

/**
 * `20260821000001_create_saga_states_table`, run as a file, against every engine.
 *
 * The table this migration creates never existed on a stock installation. `SagaWiring`
 * binds `DatabaseSagaStateStorage` as the `SagaStateStorageInterface` port whenever a
 * connection manager is bound, `SagaWiring` is registered unconditionally, and the DDL
 * lived in an `installSchema()` method whose only caller in the whole repository was the
 * storage's own unit test. So every durable saga failed at `start()` on a missing table,
 * and the one test that could have caught it created the table itself first.
 *
 * That is why the round-trip below matters more than the existence assertions above it:
 * the shape has to be the one `DatabaseSagaStateStorage` writes, not merely a table with
 * the right column names. The upsert in particular is a contract with the primary key —
 * `INSERT OR REPLACE`, `ON DUPLICATE KEY UPDATE` and `ON CONFLICT (saga_id)` all require
 * a unique key over precisely `(saga_id)`, and PostgreSQL raises 42P10 at the first save
 * rather than at migration time if it is anything else.
 */
final class SagaStatesMigrationContractTest extends SchemaMigrationContractTestCase
{
    private const string TABLE = 'saga_states';

    protected function tables(): array
    {
        return [self::TABLE];
    }

    protected function migrationPath(): string
    {
        return FrameworkSchema::SAGA_STATES;
    }

    #[Test]
    #[DataProvider('engines')]
    public function upCreatesTheTableWithEveryColumnTheStorageBinds(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->migration()->up($connection);

        self::assertTrue($this->tableExists($connection, self::TABLE));

        // The exact list `save()` binds. A missing one is not a shape difference, it is a
        // storage that cannot write at all.
        foreach ([
            'saga_id',
            'definition_id',
            'definition_version',
            'current_step_index',
            'step_results',
            'status',
            'context',
            'started_at',
            'completed_at',
        ] as $column) {
            self::assertTrue(
                $this->columnExists($connection, self::TABLE, $column),
                $column . ' is bound by DatabaseSagaStateStorage::save() on every engine',
            );
        }
    }

    /**
     * Re-runnable, and inert on the re-run.
     *
     * The runner records a migration only once `up()` returns — after the transaction around
     * it has committed — so a run that dies in that window replays from the top over a table
     * it has already created, on every engine. MySQL reaches the same replay a second way,
     * because it commits each DDL statement as it runs and can therefore leave the schema
     * half-built as well. Surviving that replay is half the requirement. The other half is that the replay costs nothing, and that half needs a
     * row to be visible at all.
     *
     * An earlier version of this test asserted only that the table still existed after the
     * second `up()`. A migration whose `up()` opened with `DROP TABLE IF EXISTS` passed it
     * on every engine — every case green — while emptying `saga_states` on each deploy that
     * replayed, stranding every unfinished saga with its compensation unrun. Seeding first
     * is what turns "the table is still there" into "the sagas are still there".
     */
    #[Test]
    #[DataProvider('engines')]
    public function aSecondUpLeavesTheTableAndItsRowsAlone(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->migration()->up($connection);
        $this->insert($connection, 'saga-survives-the-replay');

        $this->migration()->up($connection);

        self::assertTrue($this->tableExists($connection, self::TABLE));
        self::assertSame(
            1,
            $this->rowCount($connection, self::TABLE),
            'a replayed up() must not touch the rows of a table it finds already present',
        );

        // Readable as well as present: a rebuild that copied the rows into a table of a
        // different shape would keep the count and still break the storage.
        self::assertNotNull(
            new DatabaseSagaStateStorage($connection)->findById('saga-survives-the-replay'),
            'the surviving row must still be one the storage can hydrate',
        );
    }

    /**
     * The key the upsert depends on, demanded of the engine rather than read from a
     * catalogue. A table declaring PRIMARY KEY and an engine refusing the second row are
     * not the same fact, and only the second is what `save()` relies on.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theEngineRefusesTwoRowsWithTheSameSagaId(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $this->insert($connection, 'saga-dup');

        $rejected = false;

        try {
            $this->insert($connection, 'saga-dup');
        } catch (Throwable) {
            $rejected = true;
        }

        self::assertTrue(
            $rejected,
            'without a unique key over exactly (saga_id) the upsert duplicates rows on '
            . 'SQLite and MySQL and raises 42P10 on PostgreSQL',
        );
    }

    /**
     * The one deliberate narrowing. The installer wrote `saga_id TEXT PRIMARY KEY` on
     * SQLite, where a PRIMARY KEY column in a rowid table is nullable — a legacy behaviour
     * SQLite preserves on purpose. The compiled shape declares NOT NULL, so a fresh SQLite
     * install now gets what MySQL declared and PostgreSQL implied all along.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theEngineRefusesANullSagaId(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $rejected = false;

        try {
            $this->insert($connection, null);
        } catch (Throwable) {
            $rejected = true;
        }

        self::assertTrue($rejected, 'saga_id is the key; a NULL one belongs to no saga');
    }

    /**
     * The shape has to serve the storage, not merely resemble it.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theStorageRoundTripsThroughTheMigratedTable(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $storage = new DatabaseSagaStateStorage($connection);

        $state = new SagaState(
            sagaId: 'saga-round-trip',
            definitionId: 'order',
            definitionVersion: 3,
            currentStepIndex: 2,
            stepResults: [StepResult::success('charge', ['amount' => 100])],
            status: SagaStatus::Running,
            context: ['orderId' => 'ORD-1'],
            startedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            completedAt: null,
        );

        $storage->save($state);

        // The second save is the upsert, and the point of the assertion below: a key the
        // engine does not enforce leaves two rows here and `findById()` returns whichever
        // the engine happened to hand back first.
        $storage->save($state);

        $found = $storage->findById('saga-round-trip');

        self::assertNotNull($found);
        self::assertSame('saga-round-trip', $found->sagaId);
        self::assertSame(2, $found->currentStepIndex);
        self::assertSame(SagaStatus::Running, $found->status);
        self::assertSame(['orderId' => 'ORD-1'], $found->context);
        self::assertCount(1, $found->stepResults);
        self::assertSame('charge', $found->stepResults[0]->stepName);
        self::assertNull($found->completedAt);
        self::assertSame(1, $this->rowCount($connection, self::TABLE), 'the upsert must replace, not append');
    }

    /**
     * `step_results` and `context` hold whatever the saga put there, and on MySQL that has
     * to be said in the type.
     *
     * The installer declared both `LONGTEXT`; going portable spelled them `Text`, which is
     * MySQL's `TEXT` and 65,535 *bytes* rather than 4 GiB. `step_results` grows with every
     * completed step and the size of each step's output, and `context` is whatever the
     * application decided a case needs to carry — so the narrow ceiling is reachable, and
     * past it `save()` raises error 1406 under the shipped `sql_mode` or truncates where
     * strict mode is off. Truncated JSON then makes `hydrate()` throw on the next resume,
     * which turns a size limit into a saga that can no longer be continued or compensated.
     * The repair is {@see \Pulsar\Database\Schema\SchemaColumnType::BigText} on both.
     *
     * **Only MySQL can fail this test.** PostgreSQL and SQLite compile `Text` and `BigText`
     * alike to `TEXT`, which is already the widest text either engine has, so on those two
     * every assertion here holds for the unrepaired migration too. What they still pin is
     * that the type compiles to what this migration expects.
     *
     * The engine's behaviour is a separate claim and lives in a separate test — see
     * {@see aSagaStatePastTheNarrowCeilingSurvivesWhole()}. Kept in one test, whichever
     * assertion ran first would be the only one a mutation ever reached, and the other would
     * be decoration no experiment could distinguish from an empty line.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theTwoJsonColumnsCompileToTheWideTextType(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $this->assertWideText(
            $connection,
            self::TABLE,
            'step_results',
            'it grows with the number of completed steps and the output of each',
        );
        $this->assertWideText(
            $connection,
            self::TABLE,
            'context',
            'its width is decided by what the application put in the saga, not by a form',
        );
    }

    /**
     * The same claim as the storage meets it.
     *
     * A saga past the narrow ceiling has to survive the round trip whole. Refused at INSERT
     * and truncated on write are the two ways a MySQL `TEXT` column loses it — the first
     * under the shipped `sql_mode`, the second where strict mode has been switched off — and
     * reading both values back separates them from success. Truncated JSON is the worse of
     * the two, because it does not fail here at all: it fails at `hydrate()` on the next
     * resume, on a saga that can then be neither continued nor compensated.
     *
     * **Only MySQL can fail this test**, for the reason its catalogue sibling gives.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aSagaStatePastTheNarrowCeilingSurvivesWhole(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $blob = str_repeat('c', 70_000);

        $storage = new DatabaseSagaStateStorage($connection);

        $state = new SagaState(
            sagaId: 'saga-oversized',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 1,
            stepResults: [StepResult::success('charge', ['receipt' => $blob])],
            status: SagaStatus::Running,
            context: ['document' => $blob],
            startedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            completedAt: null,
        );

        try {
            $storage->save($state);
        } catch (Throwable $e) {
            self::fail(sprintf(
                'saving a saga carrying %d bytes of context was refused, which is what MySQL does '
                . 'to a TEXT column under the shipped sql_mode: %s',
                strlen($blob),
                $e->getMessage(),
            ));
        }

        $found = $storage->findById('saga-oversized');

        self::assertNotNull($found);
        self::assertSame(
            ['document' => $blob],
            $found->context,
            'a truncated context is a saga that cannot be resumed and cannot be compensated',
        );
        self::assertSame(
            ['receipt' => $blob],
            $found->stepResults[0]->output,
            'a truncated step_results makes hydrate() throw on the next resume',
        );
    }

    /**
     * The table is declared to compare text byte for byte.
     *
     * `saga_id` is the primary key and `save()` upserts on it, so under MySQL 8's default
     * `utf8mb4_0900_ai_ci` a second saga whose id differs only in case does not fail to
     * start — it silently replaces the first, and an in-flight saga loses its step results
     * and its context to a different saga's state. `findById()` then hands the survivor to
     * whichever of the two asks. That is what
     * {@see \Pulsar\Database\Schema\SchemaCollation::Exact} is on this table for.
     *
     * Which engines can fail this is set out at
     * {@see SchemaMigrationContractTestCase::assertExactCollation()}: MySQL is the only one
     * with a clause to read back. The behaviour that clause buys is asserted separately, in
     * {@see twoSagaIdsDifferingOnlyInCaseAreTwoSagas()}, so that neither half can shadow the
     * other when the repair is mutated away.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theTableIsCollatedForByteExactComparison(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $this->assertExactCollation(
            $connection,
            self::TABLE,
            ['saga_id', 'definition_id', 'step_results', 'status', 'context'],
        );
    }

    /**
     * Two saga ids differing only in case are two sagas.
     *
     * The catalogue's claim, made of the engine: a `TABLE_COLLATION` row reading
     * `utf8mb4_bin` and a server that refuses to fold `AB` into `ab` are not the same fact,
     * and it is the second that decides whether a saga keeps its own state. Asserted on all
     * three engines — PostgreSQL and SQLite compare text byte-wise by default, so here their
     * guarantee is asserted as theirs rather than as this migration's.
     */
    #[Test]
    #[DataProvider('engines')]
    public function twoSagaIdsDifferingOnlyInCaseAreTwoSagas(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $storage = new DatabaseSagaStateStorage($connection);

        $this->assertCaseVariantKeysCoexist(
            $connection,
            self::TABLE,
            'saga_id',
            static function (string $sagaId) use ($storage): void {
                $storage->save(new SagaState(
                    sagaId: $sagaId,
                    definitionId: 'definition-' . $sagaId,
                    definitionVersion: 1,
                    currentStepIndex: 0,
                    stepResults: [],
                    status: SagaStatus::Running,
                    context: [],
                    startedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
                    completedAt: null,
                ));
            },
        );

        // Coexisting is not enough on its own: every read the orchestrator makes is
        // `WHERE saga_id = :saga_id`, so the second thing to prove is that the lookup lands
        // on the saga it named rather than on its case twin.
        self::assertSame('definition-AB', $storage->findById('AB')?->definitionId);
        self::assertSame('definition-ab', $storage->findById('ab')?->definitionId);
    }

    #[Test]
    #[DataProvider('engines')]
    public function downDropsATableItReallyCreated(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $migration = $this->migration();
        $migration->up($connection);
        $migration->down($connection);

        self::assertFalse($this->tableExists($connection, self::TABLE));
    }

    /**
     * The decision this migration exists to encode. On a host that had run the old
     * installer the table pre-existed, `up()` changed nothing, and dropping it would
     * strand every unfinished saga with its compensation unrun — destruction wearing the
     * word "rollback".
     */
    #[Test]
    #[DataProvider('engines')]
    public function downRefusesToDropAPopulatedTableAndSaysHowManyRows(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $migration = $this->migration();
        $migration->up($connection);
        $this->insert($connection, 'saga-live');

        $refused = null;

        try {
            $migration->down($connection);
        } catch (RuntimeException $e) {
            $refused = $e;
        }

        self::assertNotNull($refused, 'a populated saga_states must not be dropped by a rollback');
        self::assertStringContainsString(self::TABLE, $refused->getMessage());
        self::assertStringContainsString('1', $refused->getMessage(), 'the row count is what the operator acts on');
        self::assertTrue($this->tableExists($connection, self::TABLE), 'the refusal must leave the table intact');
        self::assertSame(1, $this->rowCount($connection, self::TABLE));
    }

    /**
     * Reversing a create that never happened is a no-op, and saying so is honest: a
     * rollback that runs twice, or one after an `up()` that died before the CREATE on an
     * engine without transactional DDL, meets no table.
     */
    #[Test]
    #[DataProvider('engines')]
    public function downOnAnAbsentTableIsANoOp(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->migration()->down($connection);

        self::assertFalse($this->tableExists($connection, self::TABLE));
    }

    private function insert(ConnectionInterface $connection, ?string $sagaId): void
    {
        $connection->execute(
            'INSERT INTO ' . self::TABLE . ' (saga_id, definition_id, definition_version, '
            . 'current_step_index, step_results, status, context, started_at, completed_at) '
            . 'VALUES (:saga_id, :definition_id, :definition_version, :current_step_index, '
            . ':step_results, :status, :context, :started_at, :completed_at)',
            [
                'saga_id' => $sagaId,
                'definition_id' => 'order',
                'definition_version' => 1,
                'current_step_index' => 0,
                'step_results' => '[]',
                'status' => SagaStatus::Running->value,
                'context' => '{}',
                'started_at' => '2026-01-01 12:00:00',
                'completed_at' => null,
            ],
        );
    }
}
