<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Database\Driver;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Tests\Support\FrameworkSchema;
use Pulsar\Workflow\Internal\Storage\DatabaseTransitionLog;
use Pulsar\Workflow\Internal\Storage\DatabaseWorkflowStorage;
use Pulsar\Workflow\Storage\ClassificationLevel;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\TransitionRecord;
use Pulsar\Workflow\Storage\WorkflowInstance;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;
use RuntimeException;
use Throwable;

use function sprintf;
use function str_repeat;
use function strlen;

/**
 * `20260821000002_create_workflow_tables`, run as a file, against every engine.
 *
 * Two tables and two indexes in one migration, and the indexes are the half that used to
 * exist on one engine only: the old installers declared them inline in the MySQL
 * `CREATE TABLE` and nowhere else, because `CREATE INDEX IF NOT EXISTS` is a syntax error
 * on that engine and the other two dialects simply went without. So a PostgreSQL or SQLite
 * deployment ran the timeout sweep and `getHistory()` as full scans of tables that only
 * grow, and nothing said so.
 *
 * The index assertions below are therefore not decoration. They are the difference between
 * the shape three engines now share and the shape one of them had.
 */
final class WorkflowTablesMigrationContractTest extends SchemaMigrationContractTestCase
{
    private const string INSTANCES = 'workflow_instances';
    private const string TRANSITIONS = 'workflow_transitions';
    private const string TIMEOUT_INDEX = 'idx_workflow_instances_timeout';
    private const string HISTORY_INDEX = 'idx_workflow_transitions_instance';

    protected function tables(): array
    {
        // Child first: nothing declares the foreign key today, but a host that added one
        // out of band should still be able to run this teardown.
        return [self::TRANSITIONS, self::INSTANCES];
    }

    protected function migrationPath(): string
    {
        return FrameworkSchema::WORKFLOW_TABLES;
    }

    #[Test]
    #[DataProvider('engines')]
    public function upCreatesBothTablesAndBothIndexes(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->migration()->up($connection);

        self::assertTrue($this->tableExists($connection, self::INSTANCES));
        self::assertTrue($this->tableExists($connection, self::TRANSITIONS));

        self::assertTrue(
            $this->indexExists($connection, self::INSTANCES, self::TIMEOUT_INDEX),
            'the timeout sweep scans every instance without it',
        );
        self::assertTrue(
            $this->indexExists($connection, self::TRANSITIONS, self::HISTORY_INDEX),
            'getHistory() scans the whole append-only log without it',
        );

        // Both keys are composite, and in a composite key the order is the index. Existence
        // is decided by name everywhere in the framework, so a reordered key is invisible to
        // every other check here while costing exactly what having no index costs.
        //
        // `(status, timeout_at)` and not the reverse: the sweep asks for one status and a
        // range of times, so equality has to lead or the range scan begins at the first
        // expired row of every status. `(instance_id, created_at)` for the same reason —
        // `getHistory()` fixes the instance and orders by time, and the reverse ordering
        // makes the index unusable for either half.
        self::assertSame(
            ['status', 'timeout_at'],
            $this->indexColumns($connection, self::INSTANCES, self::TIMEOUT_INDEX),
        );
        self::assertSame(
            ['instance_id', 'created_at'],
            $this->indexColumns($connection, self::TRANSITIONS, self::HISTORY_INDEX),
        );
    }

    #[Test]
    #[DataProvider('engines')]
    public function upCreatesEveryColumnBothStoragesBind(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->migration()->up($connection);

        foreach ([
            'id',
            'definition_id',
            'definition_version',
            'current_state',
            'context',
            'version',
            'status',
            'started_at',
            'completed_at',
            'started_by',
            'timeout_at',
        ] as $column) {
            self::assertTrue($this->columnExists($connection, self::INSTANCES, $column), $column);
        }

        foreach ([
            'id',
            'instance_id',
            'from_state',
            'to_state',
            'transition_name',
            'actor',
            'reason',
            'metadata',
            'instance_version',
            'created_at',
        ] as $column) {
            self::assertTrue($this->columnExists($connection, self::TRANSITIONS, $column), $column);
        }
    }

    /**
     * A run that dies between the two tables replays from the top, so the second pass has
     * to add what the first did not and leave what it did.
     *
     * Both tables are seeded, because "leave what it did" is a claim about rows and an
     * assertion that names only tables and indexes cannot make it. A migration whose `up()`
     * opened with `DROP TABLE IF EXISTS` satisfies every existence check here and still
     * destroys the workflow instances and, worse, the append-only transition log — the
     * audit record of who moved which workflow to which state, which exists in that table
     * and nowhere else. One row apiece is enough to separate the two outcomes.
     */
    #[Test]
    #[DataProvider('engines')]
    public function upResumesAfterATableThatAlreadyExistsAndKeepsItsRows(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        new DatabaseWorkflowStorage($connection)->create(new WorkflowInstance(
            id: 'inst-1',
            definitionId: 'onboarding',
            definitionVersion: 1,
            currentState: 'draft',
            context: new ClassifiedContext(),
            version: 1,
            status: WorkflowInstanceStatus::Active,
            startedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            completedAt: null,
            startedBy: 'user-1',
            timeoutAt: null,
        ));

        new DatabaseTransitionLog($connection)->record($this->transition());

        $this->migration()->up($connection);

        self::assertTrue($this->tableExists($connection, self::INSTANCES));
        self::assertTrue($this->tableExists($connection, self::TRANSITIONS));
        self::assertTrue($this->indexExists($connection, self::INSTANCES, self::TIMEOUT_INDEX));
        self::assertTrue($this->indexExists($connection, self::TRANSITIONS, self::HISTORY_INDEX));

        self::assertSame(
            1,
            $this->rowCount($connection, self::INSTANCES),
            'a replayed up() must not discard the workflow instances it finds already there',
        );
        self::assertCount(
            1,
            new DatabaseTransitionLog($connection)->getHistory('inst-1'),
            'the transition log is append-only audit evidence; a replay must not truncate it',
        );
    }

    /**
     * The index work sits outside the table guard on purpose. An interrupted run that got
     * as far as creating the table would otherwise resume, find it present, skip the whole
     * branch and be recorded as applied over a table with no index at all.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aRunInterruptedBeforeTheIndexesIsCompletedByTheNext(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        // Exactly what a run that died after the two CREATE TABLEs leaves behind.
        $indexes = new IndexOperations($connection);
        $indexes->ensureAbsent(self::INSTANCES, self::TIMEOUT_INDEX);
        $indexes->ensureAbsent(self::TRANSITIONS, self::HISTORY_INDEX);

        self::assertFalse($this->indexExists($connection, self::INSTANCES, self::TIMEOUT_INDEX), 'fixture precondition');

        $this->migration()->up($connection);

        self::assertTrue($this->indexExists($connection, self::INSTANCES, self::TIMEOUT_INDEX));
        self::assertTrue($this->indexExists($connection, self::TRANSITIONS, self::HISTORY_INDEX));
    }

    #[Test]
    #[DataProvider('engines')]
    public function theTransitionLogRoundTripsThroughTheMigratedTable(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $log = new DatabaseTransitionLog($connection);

        $log->record(new TransitionRecord(
            id: 'tr-1',
            instanceId: 'inst-1',
            fromState: 'draft',
            toState: 'review',
            transitionName: 'submit',
            actor: 'user-1',
            reason: null,
            metadata: ['ip' => '203.0.113.7'],
            instanceVersion: 1,
            createdAt: new DateTimeImmutable('2026-01-01 12:00:00'),
        ));

        $history = $log->getHistory('inst-1');

        self::assertCount(1, $history);
        self::assertSame('review', $history[0]->toState);
        self::assertNull($history[0]->reason, 'reason is the only nullable text in either table');
        self::assertSame(['ip' => '203.0.113.7'], $history[0]->metadata);
        self::assertSame('review', $log->reconstructState('inst-1'));
    }

    /**
     * `context` and `metadata` are wide, `reason` is narrow, and the line between them is
     * the installer's own — restated portably, and read back per column.
     *
     * The wide two are serialized documents whose size the data decides: an instance's
     * `ClassifiedContext` and a transition's metadata, each written and read back as one
     * JSON string. A context whose values are encryptor output rather than plaintext
     * carries a nonce, a tag and base64 expansion on every field, so it reaches MySQL's
     * 65,535-*byte* `TEXT` ceiling on a fraction of the fields the plaintext would have
     * needed — and what is lost when it does is the state of a running case. `reason` is
     * prose a human typed into a field, was `TEXT NULL` on MySQL in the same statement that
     * made `metadata` `LONGTEXT`, and stays narrow.
     *
     * That contrast is why this test asserts `reason` as well. Asserting only the wide
     * columns would be satisfied by a compiler that had lost the distinction and made
     * every text column `LONGTEXT`; asserting both shows the migration chose per column and
     * that the choice landed where it was made.
     *
     * **Only MySQL can fail either half.** On PostgreSQL and SQLite `Text` and `BigText`
     * are both `TEXT`, so the wide assertion and the narrow assertion are the same
     * assertion there and neither can separate this migration from one with the repair
     * reverted. The engine that can is the engine whose ceiling made the repair necessary.
     *
     * What the engines do with those types is a separate claim in a separate test — see
     * {@see aContextAndAMetadataPastTheNarrowCeilingSurviveWhole()}. Kept in one test,
     * whichever assertion ran first would be the only one a mutation ever reached.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theDocumentColumnsAreWideAndTheOperatorNoteIsDeliberatelyNot(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $this->assertWideText(
            $connection,
            self::INSTANCES,
            'context',
            'a ClassifiedContext of encryptor output is sized by the case, not by a form',
        );
        $this->assertWideText(
            $connection,
            self::TRANSITIONS,
            'metadata',
            'a transition carries whatever the caller attached to it',
        );

        $this->assertNarrowText(
            $connection,
            self::TRANSITIONS,
            'reason',
            'an operator typed it into a field somebody sized, and widening it would invent a '
            . 'divergence from the installer rather than close one',
        );
    }

    /**
     * The wide claim as the two storages meet it: a document past the narrow ceiling,
     * written and read back whole.
     *
     * Refused at INSERT and truncated on write are the two failures a MySQL `TEXT` column
     * produces — the first under the shipped `sql_mode`, the second where strict mode has
     * been switched off — and comparing the value back separates both from success. Both
     * storages are exercised because both write their own statement, and a passing
     * `workflow_instances` says nothing about `workflow_transitions`.
     *
     * **Only MySQL can fail this test**, for the reason its catalogue sibling gives.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aContextAndAMetadataPastTheNarrowCeilingSurviveWhole(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $blob = str_repeat('d', 70_000);

        $storage = new DatabaseWorkflowStorage($connection);
        $log = new DatabaseTransitionLog($connection);

        try {
            $storage->create(new WorkflowInstance(
                id: 'inst-oversized',
                definitionId: 'onboarding',
                definitionVersion: 1,
                currentState: 'draft',
                context: new ClassifiedContext()->set('document', $blob, ClassificationLevel::Internal),
                version: 1,
                status: WorkflowInstanceStatus::Active,
                startedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
                completedAt: null,
                startedBy: 'user-1',
                timeoutAt: null,
            ));

            $log->record(new TransitionRecord(
                id: 'tr-oversized',
                instanceId: 'inst-oversized',
                fromState: 'draft',
                toState: 'review',
                transitionName: 'submit',
                actor: 'user-1',
                reason: null,
                metadata: ['document' => $blob],
                instanceVersion: 1,
                createdAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            ));
        } catch (Throwable $e) {
            self::fail(sprintf(
                'writing a %d-byte document was refused, which is what MySQL does to a TEXT '
                . 'column under the shipped sql_mode: %s',
                strlen($blob),
                $e->getMessage(),
            ));
        }

        self::assertSame(
            $blob,
            $storage->findById('inst-oversized')?->context->get('document'),
            'a truncated context is the state of a running case, lost',
        );
        self::assertSame(
            ['document' => $blob],
            $log->getHistory('inst-oversized')[0]->metadata,
            'the transition log is audit evidence; a truncated entry is evidence nobody can read',
        );
    }

    /**
     * Both tables are declared to compare text byte for byte.
     *
     * Both key on an application-supplied identifier, and every statement either storage
     * issues after the insert selects on one: the optimistic lock is
     * `UPDATE ... WHERE id = :id AND version = :expected_version`, and `getHistory()` reads
     * `WHERE instance_id = :instance_id`. Under MySQL 8's default `utf8mb4_0900_ai_ci` two
     * ids differing only in case are one key — so `create()` refuses the second workflow
     * outright, and a transition recorded against one case shows up in the history of the
     * other, which is an audit log attributing a state change to a workflow that never made
     * it. That is what {@see \Pulsar\Database\Schema\SchemaCollation::Exact} is on both
     * tables for.
     *
     * Which engines can fail this is set out at
     * {@see SchemaMigrationContractTestCase::assertExactCollation()}: MySQL is the only one
     * with a clause to read back. The behaviour that clause buys is asserted separately, in
     * {@see twoIdsDifferingOnlyInCaseAreTwoWorkflowsAndTwoTransitions()}, so that neither
     * half can shadow the other when the repair is mutated away.
     */
    #[Test]
    #[DataProvider('engines')]
    public function bothTablesAreCollatedForByteExactComparison(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $this->assertExactCollation($connection, self::INSTANCES, [
            'id',
            'definition_id',
            'current_state',
            'context',
            'status',
            'started_by',
        ]);

        $this->assertExactCollation($connection, self::TRANSITIONS, [
            'id',
            'instance_id',
            'from_state',
            'to_state',
            'transition_name',
            'actor',
            'reason',
            'metadata',
        ]);
    }

    /**
     * Two instance ids differing only in case are two workflows, and two transition ids are
     * two transitions.
     *
     * The catalogue's claim, made of the engine. Asserted on all three: PostgreSQL and
     * SQLite compare text byte-wise by default, so here their guarantee is asserted as
     * theirs rather than as this migration's.
     */
    #[Test]
    #[DataProvider('engines')]
    public function twoIdsDifferingOnlyInCaseAreTwoWorkflowsAndTwoTransitions(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $storage = new DatabaseWorkflowStorage($connection);

        $this->assertCaseVariantKeysCoexist(
            $connection,
            self::INSTANCES,
            'id',
            static function (string $id) use ($storage): void {
                $storage->create(new WorkflowInstance(
                    id: $id,
                    definitionId: 'definition-' . $id,
                    definitionVersion: 1,
                    currentState: 'draft',
                    context: new ClassifiedContext(),
                    version: 1,
                    status: WorkflowInstanceStatus::Active,
                    startedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
                    completedAt: null,
                    startedBy: 'user-1',
                    timeoutAt: null,
                ));
            },
        );

        self::assertSame('definition-AB', $storage->findById('AB')?->definitionId);
        self::assertSame('definition-ab', $storage->findById('ab')?->definitionId);

        $log = new DatabaseTransitionLog($connection);

        $this->assertCaseVariantKeysCoexist(
            $connection,
            self::TRANSITIONS,
            'id',
            static function (string $id) use ($log): void {
                $log->record(new TransitionRecord(
                    id: $id,
                    instanceId: $id,
                    fromState: 'draft',
                    toState: 'state-' . $id,
                    transitionName: 'submit',
                    actor: 'user-1',
                    reason: null,
                    metadata: [],
                    instanceVersion: 1,
                    createdAt: new DateTimeImmutable('2026-01-01 12:00:00'),
                ));
            },
        );

        // `instance_id` is not the key, but it is what the history is read by, so a
        // case-insensitive column there puts one workflow's transitions into another's
        // audit trail. Both histories are read back to show the two do not bleed together.
        self::assertSame('state-AB', $log->getHistory('AB')[0]->toState);
        self::assertSame('state-ab', $log->getHistory('ab')[0]->toState);
    }

    #[Test]
    #[DataProvider('engines')]
    public function downDropsBothTablesWhenNeitherHoldsRows(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $migration = $this->migration();
        $migration->up($connection);
        $migration->down($connection);

        self::assertFalse($this->tableExists($connection, self::INSTANCES));
        self::assertFalse($this->tableExists($connection, self::TRANSITIONS));
    }

    /**
     * Both tables are inspected before either is dropped, so a refusal leaves the schema
     * exactly as it found it rather than half-reversed — which is the state an operator
     * would then have to reason about with the rollback already reported as failed.
     */
    #[Test]
    #[DataProvider('engines')]
    public function downRefusesWhenTheAuditLogHoldsRowsAndDropsNeitherTable(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $migration = $this->migration();
        $migration->up($connection);

        new DatabaseTransitionLog($connection)->record($this->transition());

        $refused = null;

        try {
            $migration->down($connection);
        } catch (RuntimeException $e) {
            $refused = $e;
        }

        self::assertNotNull($refused);
        self::assertStringContainsString(self::TRANSITIONS, $refused->getMessage());
        self::assertTrue($this->tableExists($connection, self::TRANSITIONS));
        self::assertTrue(
            $this->tableExists($connection, self::INSTANCES),
            'the instances table is inspected before either is dropped, so a refusal on the '
            . 'transitions table must not leave it half-reversed',
        );
    }

    /**
     * A table that is absent contributes nothing to the refusal: there is nothing to drop
     * and nothing to lose, and demanding its presence would turn a partially applied
     * migration into an unrollbackable one.
     */
    #[Test]
    #[DataProvider('engines')]
    public function downSucceedsWhenOnlyOneOfTheTwoTablesWasEverCreated(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $migration = $this->migration();
        $migration->up($connection);
        $connection->execute('DROP TABLE ' . self::TRANSITIONS);

        $migration->down($connection);

        self::assertFalse($this->tableExists($connection, self::INSTANCES));
    }

    #[Test]
    #[DataProvider('engines')]
    public function downOnAnAbsentSchemaIsANoOp(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->migration()->down($connection);

        self::assertFalse($this->tableExists($connection, self::INSTANCES));
        self::assertFalse($this->tableExists($connection, self::TRANSITIONS));
    }

    /**
     * One transition of the `workflow_transitions` shape, for the tests that need a row in
     * the log rather than a particular payload in it.
     */
    private function transition(): TransitionRecord
    {
        return new TransitionRecord(
            id: 'tr-1',
            instanceId: 'inst-1',
            fromState: 'draft',
            toState: 'review',
            transitionName: 'submit',
            actor: 'user-1',
            reason: null,
            metadata: [],
            instanceVersion: 1,
            createdAt: new DateTimeImmutable('2026-01-01 12:00:00'),
        );
    }
}
