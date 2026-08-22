<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\TableDefinition;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;
use Pulsar\Event\Internal\Outbox\DatabaseOutboxPort;
use Pulsar\Tests\Support\FrameworkSchema;
use RuntimeException;
use Throwable;

use function sprintf;
use function str_contains;
use function str_repeat;
use function strlen;

/**
 * `20260821000004_create_event_outbox_table`, run as a file, against every engine.
 *
 * This migration carries two jobs, and the second one has never run anywhere. The outbox
 * table used to be created at boot by `DatabaseOutboxPort::installSchema()`, and the
 * dead-letter column was added by a sibling `migrateSchema()` that no production code path
 * ever called — only a test did. So a deployment that installed before the dead-letter
 * feature and upgraded afterwards is still missing `dead_lettered_at` and its index, and
 * step two of `up()` is the repair, folded in here where it will finally execute.
 *
 * `upgradesALegacyTable...` is that case, reproduced: the table without the column, and a
 * pending index compiled without it. `IndexOperations::ensure()` decides existence by name
 * and would leave the stale index in place, so the migration drops it — keyed on the
 * absence of the dead-letter index, which is a durable fact about the table rather than a
 * flag an earlier statement in the same run invalidates.
 *
 * ## Three things here leave no trace, and each has its own witness
 *
 * That drop is the one step of the migration with no behavioural consequence, and it is not
 * alone. The index keeps its name, the table keeps its rows, and every query the port issues
 * answers exactly as it did — only more slowly, on a table that only grows. An earlier
 * version of this suite asked whether the index existed and whether events read back;
 * deleting the repair changed neither answer, and all of it stayed green over precisely the
 * defect it was written to close. Three assertions exist for that reason and no other:
 *
 *   - {@see SchemaMigrationContractTestCase::indexColumns()} reads what the index is built
 *     over, from each engine's own catalogue, before and after the upgrade.
 *   - {@see assertPendingIndexIsNarrowed()} reads its predicate, which is invisible for the
 *     same reason one step further along: a partial index without its `WHERE` clause covers
 *     more rows and returns the same ones.
 *   - {@see theLegacyRebuildRunsOnceAndThenStops()} watches the statements, because the
 *     self-clearing guard is the one claim that leaves no schema behind at all — an
 *     unguarded second pass drops and rebuilds an index that is already correct, and the
 *     database afterwards is identical.
 *
 * The wide-text and collation repairs are asserted here too, and both are read per engine:
 * see {@see thePayloadColumnsAreWideAndTheErrorColumnIsDeliberatelyNot()} and
 * {@see twoEventIdsDifferingOnlyInCaseAreTwoEventsAndAStampReachesOne()}, each of which says
 * which engines its assertions can actually fail on.
 */
final class EventOutboxMigrationContractTest extends SchemaMigrationContractTestCase
{
    private const string TABLE = 'outbox_events';
    private const string PENDING_INDEX = 'outbox_events_pending_idx';
    private const string DEADLETTER_INDEX = 'outbox_events_deadletter_idx';

    protected function tables(): array
    {
        return [self::TABLE];
    }

    protected function migrationPath(): string
    {
        return FrameworkSchema::OUTBOX_EVENTS;
    }

    #[Test]
    #[DataProvider('engines')]
    public function upCreatesTheTableItsColumnsAndBothIndexes(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->migration()->up($connection);

        self::assertTrue($this->tableExists($connection, self::TABLE));

        foreach ([
            'event_id',
            'event_type',
            'schema_version',
            'payload_json',
            'payload_hash',
            'metadata_json',
            'origin_module',
            'scope',
            'publish_attempts',
            'last_error',
            'published_at',
            'dead_lettered_at',
            'created_at',
        ] as $column) {
            self::assertTrue($this->columnExists($connection, self::TABLE, $column), $column);
        }

        self::assertTrue($this->indexExists($connection, self::TABLE, self::PENDING_INDEX));
        self::assertTrue($this->indexExists($connection, self::TABLE, self::DEADLETTER_INDEX));

        self::assertSame(
            $this->pendingIndexColumns($connection),
            $this->indexColumns($connection, self::TABLE, self::PENDING_INDEX),
        );

        self::assertSame(
            ['dead_lettered_at'],
            $this->indexColumns($connection, self::TABLE, self::DEADLETTER_INDEX),
        );

        $this->assertPendingIndexIsNarrowed($connection);
        $this->assertDeadLetterIndexIsNarrowed($connection);
    }

    /**
     * The two payload columns are wide and `last_error` is deliberately not.
     *
     * `payload_json` and `metadata_json` hold whatever a domain event carries — a width the
     * data decides — and the installer wrote both `LONGTEXT` for that reason.
     * {@see \Pulsar\Database\Schema\SchemaColumnType::Text} would compile to MySQL's `TEXT`,
     * which stops at 65,535 *bytes*: under the shipped `sql_mode` that is error 1406 on the
     * `store()` inside somebody's domain transaction, and with strict mode off it is a
     * truncated payload that also invalidates `payload_hash`. `last_error` holds a driver's
     * exception message, was plain `TEXT` in the installer, and stays narrow here.
     *
     * Asserting the narrow one is what keeps the wide two honest. A compiler that had lost
     * the distinction and emitted `LONGTEXT` for every text column would satisfy the first
     * two assertions on its own; only the third says the migration made the choice per
     * column, and made it where it said it did.
     *
     * **Only MySQL can fail any of this.** PostgreSQL and SQLite spell all three `TEXT`,
     * which is already the widest text either engine has, so there the wide assertion and
     * the narrow assertion are the same assertion and neither separates this migration from
     * one with `BigText` reverted to `Text`.
     *
     * What the engine does with those types is a separate claim in a separate test — see
     * {@see anEnvelopePastTheNarrowCeilingSurvivesWhole()}. Kept in one test, whichever
     * assertion ran first would be the only one a mutation ever reached.
     */
    #[Test]
    #[DataProvider('engines')]
    public function thePayloadColumnsAreWideAndTheErrorColumnIsDeliberatelyNot(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $this->assertWideText(
            $connection,
            self::TABLE,
            'payload_json',
            'a domain event carries whatever it carries, and this is the column it lands in',
        );
        $this->assertWideText(
            $connection,
            self::TABLE,
            'metadata_json',
            'the same, for everything travelling beside the payload',
        );

        $this->assertNarrowText(
            $connection,
            self::TABLE,
            'last_error',
            "it holds a driver's exception message, which is the installer's own line between "
            . 'the two and the right place for it',
        );
    }

    /**
     * The ceiling as the port meets it.
     *
     * An oversized envelope has to survive the round trip whole. Refused at INSERT and
     * silently truncated are the two things a narrow column does on MySQL — the first under
     * the shipped `sql_mode`, the second where strict mode has been switched off — and the
     * first of those happens *inside the caller's domain transaction*, which is what makes
     * this column's width an availability property rather than a storage one. Reading the
     * payload back separates both outcomes from success.
     *
     * **Only MySQL can fail this test**, for the reason its catalogue sibling gives.
     */
    #[Test]
    #[DataProvider('engines')]
    public function anEnvelopePastTheNarrowCeilingSurvivesWhole(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $blob = str_repeat('e', 70_000);

        $port = new DatabaseOutboxPort($connection);

        try {
            $port->store(EventEnvelope::fromArray([
                'event_id' => 'event-oversized',
                'event_type' => 'invoice.issued',
                'schema_version' => 1,
                'metadata' => $this->metadata()->toArray(),
                'payload' => ['document' => $blob],
                'origin_module' => 'tests',
            ]));
        } catch (Throwable $e) {
            self::fail(sprintf(
                'storing a %d-byte payload was refused, which is what MySQL does to a TEXT column '
                . 'under the shipped sql_mode — inside the caller\'s domain transaction: %s',
                strlen($blob),
                $e->getMessage(),
            ));
        }

        $pending = $port->pendingEvents();

        self::assertCount(1, $pending);
        self::assertSame(
            ['document' => $blob],
            $pending[0]->payload,
            'a truncated payload is an event the subscriber cannot act on, and a payload_hash '
            . 'that no longer describes it',
        );
    }

    /**
     * The table is declared to compare text byte for byte.
     *
     * `event_id` is the primary key and every write after the insert selects on it, so under
     * MySQL 8's default `utf8mb4_0900_ai_ci` two ids differing only in case are one key.
     * {@see \Pulsar\Database\Schema\SchemaCollation::Exact} is what the migration asks for
     * to stop that, and it brings `DEFAULT CHARSET=utf8mb4` with it because MySQL derives a
     * table's character set from its collation.
     *
     * Which engines can fail this is set out at
     * {@see SchemaMigrationContractTestCase::assertExactCollation()}: MySQL is the only one
     * with a clause to read back. What that clause buys is asserted as behaviour in
     * {@see twoEventIdsDifferingOnlyInCaseAreTwoEventsAndAStampReachesOne()}.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theTableIsCollatedForByteExactComparison(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $this->assertExactCollation($connection, self::TABLE, [
            'event_id',
            'event_type',
            'payload_json',
            'payload_hash',
            'metadata_json',
            'origin_module',
            'scope',
            'last_error',
        ]);
    }

    /**
     * Two event ids differing only in case are two events, and a stamp reaches only the one
     * it names.
     *
     * `event_id` is the primary key and every write after the insert selects on it:
     * `markPublished()`, `recordFailure()` and the relay all match
     * `WHERE event_id = :event_id`. Under MySQL 8's default `utf8mb4_0900_ai_ci` the two
     * ids are one key, so the second `store()` is rejected as a duplicate — inside the
     * domain transaction that produced the event — and where it is not, a publish stamp
     * lands on a row nobody named and an unpublished event is marked published. Nothing
     * downstream ever reports the event that was never sent.
     *
     * The framework's own ids cannot collide this way: `EventEnvelope::wrap()` is
     * `bin2hex()` over 16 random bytes. An application supplying its own id through
     * `fromArray()` is not held to that, which is why
     * {@see \Pulsar\Database\Schema\SchemaCollation::Exact} is on the table rather than in
     * an id generator — and why the fixture below builds its envelopes the way an
     * application would.
     *
     * The catalogue's half of the claim is
     * {@see theTableIsCollatedForByteExactComparison()}, kept separate so that neither half
     * can shadow the other when the repair is mutated away. This one is the engine's, and
     * runs on all three.
     */
    #[Test]
    #[DataProvider('engines')]
    public function twoEventIdsDifferingOnlyInCaseAreTwoEventsAndAStampReachesOne(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $port = new DatabaseOutboxPort($connection);

        $this->assertCaseVariantKeysCoexist(
            $connection,
            self::TABLE,
            'event_id',
            function (string $eventId) use ($port): void {
                $port->store(EventEnvelope::fromArray([
                    'event_id' => $eventId,
                    'event_type' => 'type.' . $eventId,
                    'schema_version' => 1,
                    'metadata' => $this->metadata()->toArray(),
                    'payload' => ['id' => $eventId],
                    'origin_module' => 'tests',
                ]));
            },
        );

        // The half that costs a message rather than a row. `markPublished()` stamps
        // `WHERE event_id = :event_id`, so under a case-insensitive key it marks both — and
        // the event that leaves the pending set without being published is one no
        // subscriber will ever see and nothing will ever report.
        $port->markPublished('AB');

        $pending = $port->pendingEvents();

        self::assertCount(1, $pending, 'publishing one event must not retire the other');
        self::assertSame(
            'type.ab',
            $pending[0]->eventType,
            'the stamp landed on the wrong row: the event still waiting is the one that was '
            . 'marked published',
        );
    }

    /**
     * Re-runnable, and inert on the re-run.
     *
     * The stored event is the whole assertion. An outbox row is a message a domain
     * transaction already committed and nobody has published yet, so a migration whose
     * `up()` opened with `DROP TABLE IF EXISTS` — which satisfies every existence check
     * below — would silently drop announcements the rest of the system is waiting on, with
     * no failure anywhere to say so. Checking the row back turns a replayed deploy from an
     * unobservable data-loss event into a red test.
     *
     * The second pass runs through a {@see RecordingConnection} so the stronger claim can be
     * made as well: it writes nothing at all. On a fresh install neither index exists when
     * `dropStalePendingIndex()` is reached, so the guard is not what spares the table here —
     * the drop simply has nothing to drop — and the pass costs the catalogue lookups its
     * guards are made of and not one statement more.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aSecondUpLeavesTheTableItsIndexesAndItsRowsAlone(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $port = new DatabaseOutboxPort($connection);
        $port->store($this->envelope('order.placed'));

        $recorder = new RecordingConnection($connection);
        $this->migration()->up($recorder);

        self::assertSame(
            [],
            $recorder->drainExecuted(),
            'a replayed up() over a table this migration already built has nothing left to do, '
            . 'and every guard it consults is a catalogue read rather than a write',
        );

        self::assertTrue($this->indexExists($connection, self::TABLE, self::PENDING_INDEX));
        self::assertTrue($this->indexExists($connection, self::TABLE, self::DEADLETTER_INDEX));

        self::assertCount(
            1,
            $port->pendingEvents(),
            'a replayed up() must not discard events a domain transaction already committed',
        );
    }

    /**
     * The upgrade path that has never executed on any host.
     *
     * The fixture is the shape a pre-dead-letter installation has: the table without
     * `dead_lettered_at`, and a pending index built over the columns that existed then. The
     * migration must add the column, notice that the pending index predates it, and rebuild
     * it — because `ensure()` matches on name and would otherwise leave the old shape in
     * place while the docblock claimed a repair.
     *
     * The index shape is read from the catalogue, before and after, and that is the whole
     * point of this test. Every other observation available here — the index exists, the
     * port reads and writes, the backlog survives — is true of a stale index as well as a
     * repaired one, so a suite built only from those passes against a migration with the
     * repair deleted. Only {@see indexColumns()} can tell the two apart.
     */
    #[Test]
    #[DataProvider('engines')]
    public function upgradesALegacyTableThatPredatesTheDeadLetterColumn(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->createLegacyTable($connection);

        self::assertFalse($this->columnExists($connection, self::TABLE, 'dead_lettered_at'), 'fixture precondition');
        self::assertTrue($this->indexExists($connection, self::TABLE, self::PENDING_INDEX), 'fixture precondition');

        // The fixture has to be a shape the migration would have to change, or the
        // assertion after `up()` is true before it runs and proves nothing. A legacy
        // installer that happened to write today's key would make this test vacuous
        // rather than red, so it is asserted rather than assumed.
        self::assertNotSame(
            $this->pendingIndexColumns($connection),
            $this->indexColumns($connection, self::TABLE, self::PENDING_INDEX),
            'fixture precondition: the legacy index must differ from the shape under test',
        );

        // An event the legacy host committed and never published. The port's INSERT does
        // not name dead_lettered_at, so it writes to the old shape unchanged — which is
        // why a real pre-upgrade backlog can be reproduced rather than approximated.
        $port = new DatabaseOutboxPort($connection);
        $port->store($this->envelope('legacy.backlog'));

        $this->migration()->up($connection);

        self::assertTrue(
            $this->columnExists($connection, self::TABLE, 'dead_lettered_at'),
            'the column the never-called migrateSchema() was supposed to add',
        );
        self::assertTrue($this->indexExists($connection, self::TABLE, self::DEADLETTER_INDEX));
        self::assertTrue($this->indexExists($connection, self::TABLE, self::PENDING_INDEX));

        // The upgrade has to be additive. `pendingEvents()` filters on
        // `dead_lettered_at IS NULL`, so a column added NOT NULL with a non-null default
        // would leave every pre-existing event in the table and out of the relay's sight —
        // a backlog that neither publishes nor reports itself missing. Reading the legacy
        // event back proves the row survived the ALTER and is still pending.
        $pending = $port->pendingEvents();

        self::assertCount(1, $pending, 'the pre-upgrade backlog must survive and stay pending');
        self::assertSame('legacy.backlog', $pending[0]->eventType);

        // The rebuild, read back from the catalogue. This is the one assertion in the file
        // that the repair alone can satisfy: `ensure()` decides existence by name, finds
        // `outbox_events_pending_idx` present and returns without looking at its columns, so
        // unless something dropped the legacy index first the shape below is still the one
        // the fixture wrote.
        self::assertSame(
            $this->pendingIndexColumns($connection),
            $this->indexColumns($connection, self::TABLE, self::PENDING_INDEX),
            'the pending index still has its pre-dead-letter shape: ensure() matched the name '
            . 'and did nothing, so the drop that has to precede it did not run',
        );

        // The predicate is the other half of the rebuilt definition, and the legacy fixture
        // carries none at all — so where the engine has partial indexes this separates a
        // rebuilt index from the fixture's just as the key does, and it is the assertion
        // that also notices a migration that stopped asking for the narrowing.
        $this->assertPendingIndexIsNarrowed($connection);
        $this->assertDeadLetterIndexIsNarrowed($connection);

        // And it still serves the relay afterwards. A rebuild that produced an index the
        // planner will not use is a slower table with a passing shape assertion, so the
        // read the relay actually issues is made against the repaired table too.
        $port->store($this->envelope('order.placed'));

        self::assertCount(2, $port->pendingEvents(), 'the repaired table has to serve the relay');
    }

    /**
     * A resumed run must not keep rebuilding. Once the dead-letter index exists the rebuild
     * self-clears, so a second pass over an upgraded table leaves both indexes alone.
     *
     * ## Why this test watches the statements instead of the schema
     *
     * "Runs once" is observable in the schema; "and then stops" is not, and an earlier
     * version of this test could not tell the two apart. Delete the self-clearing guard from
     * `dropStalePendingIndex()` and the second pass drops the pending index it has already
     * repaired and immediately rebuilds it — same name, same columns, same predicate. Every
     * catalogue read below answers identically either way, every row is where it was, and
     * the test stayed green over a migration that re-does its repair on every deploy
     * forever. On a large PostgreSQL outbox that unguarded rebuild is a plain `CREATE INDEX`
     * taking a `ShareLock` on `outbox_events`, which conflicts with every write — the relay
     * unable to stamp a publication and the application unable to store an event, once per
     * deploy, for a repair that was finished the first time.
     *
     * So the connection handed to `up()` is a {@see RecordingConnection}, and the two claims
     * in the test's name are asserted separately: the first pass issues exactly one drop of
     * the pending index, and the second issues no statement at all. The second is the
     * stronger form of "stops" — a replay over an up-to-date outbox is a read-only run, and
     * every guard in this migration is a catalogue lookup precisely so that it can be.
     *
     * The shape assertions stay, and they carry the other half: a repair that never ran is
     * not rescued by running again, so the pending index would still carry its
     * pre-dead-letter key after two full passes on a table the operator now believes is
     * upgraded.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theLegacyRebuildRunsOnceAndThenStops(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->createLegacyTable($connection);

        $recorder = new RecordingConnection($connection);

        $this->migration()->up($recorder);
        $firstPass = $recorder->drainExecuted();

        $this->migration()->up($recorder);
        $secondPass = $recorder->drainExecuted();

        self::assertCount(
            1,
            $this->dropsOfThePendingIndex($firstPass),
            'the first pass over a legacy table has to drop the stale pending index exactly once, '
            . 'because ensure() matches on the name and would otherwise keep the pre-dead-letter '
            . 'shape',
        );

        self::assertSame(
            [],
            $secondPass,
            'the second pass over the upgraded table must write nothing at all. The guard is '
            . 'keyed on the absence of the dead-letter index, which the first pass created, so '
            . 'the rebuild self-clears; without it this pass drops and recreates an index that is '
            . 'already correct — same name, same columns, same predicate, which is why nothing '
            . 'but the statement log can tell the two runs apart.',
        );

        self::assertTrue($this->indexExists($connection, self::TABLE, self::PENDING_INDEX));
        self::assertTrue($this->indexExists($connection, self::TABLE, self::DEADLETTER_INDEX));

        self::assertSame(
            $this->pendingIndexColumns($connection),
            $this->indexColumns($connection, self::TABLE, self::PENDING_INDEX),
            'a resumed run has to leave the repaired shape in place, not revert it',
        );

        $this->assertPendingIndexIsNarrowed($connection);
    }

    /**
     * The index work sits outside the column guard on purpose. On MySQL the ALTER commits by
     * itself, so a run dying immediately after it replays over a table that already has the
     * column; on PostgreSQL and SQLite the same replay arrives through the other door — `up()`
     * committed and the runner died before recording it. Either way the resumed run finds the
     * column present, and would skip straight past index creation if the two shared a branch,
     * leaving the relay to table-scan the outbox on every poll.
     *
     * The interrupted state is reproduced directly rather than waited for, so the assertion
     * holds on every engine instead of only on the one that can half-build a schema.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aRunThatDiedAfterTheAlterStillGetsItsIndexes(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $indexes = new IndexOperations($connection);
        $indexes->ensureAbsent(self::TABLE, self::PENDING_INDEX);
        $indexes->ensureAbsent(self::TABLE, self::DEADLETTER_INDEX);

        self::assertTrue($this->columnExists($connection, self::TABLE, 'dead_lettered_at'), 'fixture precondition');

        $this->migration()->up($connection);

        self::assertTrue($this->indexExists($connection, self::TABLE, self::PENDING_INDEX));
        self::assertTrue($this->indexExists($connection, self::TABLE, self::DEADLETTER_INDEX));
    }

    #[Test]
    #[DataProvider('engines')]
    public function thePortRoundTripsThroughTheMigratedTable(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->migration()->up($connection);

        $port = new DatabaseOutboxPort($connection);
        $envelope = $this->envelope('invoice.issued');

        $port->store($envelope);

        $pending = $port->pendingEvents();
        self::assertCount(1, $pending);
        self::assertSame('invoice.issued', $pending[0]->eventType);

        $port->markPublished($envelope->eventId);
        self::assertSame([], $port->pendingEvents(), 'a published event leaves the pending set');
    }

    #[Test]
    #[DataProvider('engines')]
    public function downDropsAnEmptyOutbox(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $migration = $this->migration();
        $migration->up($connection);
        $migration->down($connection);

        self::assertFalse($this->tableExists($connection, self::TABLE));
    }

    /**
     * The finding this replaced: `down()` used to drop `outbox_events` on hosts where
     * `up()` provably changed nothing, destroying events a domain transaction had already
     * committed and nobody had published yet.
     */
    #[Test]
    #[DataProvider('engines')]
    public function downRefusesToDropAnOutboxHoldingUnpublishedEvents(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $migration = $this->migration();
        $migration->up($connection);

        new DatabaseOutboxPort($connection)->store($this->envelope('order.placed'));

        $refused = null;

        try {
            $migration->down($connection);
        } catch (RuntimeException $e) {
            $refused = $e;
        }

        self::assertNotNull($refused);
        self::assertStringContainsString(self::TABLE, $refused->getMessage());
        self::assertStringContainsString('1', $refused->getMessage(), 'the count is what the operator drains');
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

    /**
     * The outbox as a host that installed before the dead-letter feature has it.
     *
     * Compiled from the same {@see TableDefinition} the migration uses, minus the one
     * column — not transcribed by hand. A fixture written per engine would be three more
     * dialects to keep in step, and the first one to drift would make this test pass
     * against a shape no host has ever had.
     *
     * The pending index is `(published_at, created_at)`, which is what all three installers
     * wrote before `dead_lettered_at` existed — SQLite and MySQL over exactly those two
     * columns with no predicate, PostgreSQL over the same two with `WHERE published_at IS
     * NULL`. The column list therefore needs no branch here: there was none to reproduce.
     * The predicate is left off, so the fixture is byte-for-byte the legacy SQLite and MySQL
     * index and a predicate-less superset of the legacy PostgreSQL one — wider, which can
     * only make a stale index harder to notice, never easier.
     *
     * That is exactly the shape `ensure()` matches by name and would keep, and the shape the
     * migration has to notice.
     */
    private function createLegacyTable(ConnectionInterface $connection): void
    {
        $capabilities = new SchemaCapabilities(
            $connection->driver(),
            $connection,
            $connection->variant(),
        );

        $compiler = new DdlCompiler($connection->driver(), $capabilities);

        foreach ($compiler->compileCreate($this->legacyDefinition()) as $sql) {
            $connection->execute($sql);
        }

        new IndexOperations($connection)->ensure(
            self::TABLE,
            self::PENDING_INDEX,
            ['published_at', 'created_at'],
        );
    }

    /**
     * The pending index this migration is supposed to leave behind, in key order.
     *
     * Written out here rather than read from the migration. Asking the code under test what
     * shape it produces and then asserting it produced that shape is not an assertion, and
     * the branch is the reason this cannot be one constant: where the engine has partial
     * indexes the predicate carries `published_at` and `dead_lettered_at`, so the key is the
     * one sort column; where it does not, the dialect discards the predicate and the two
     * filtered columns have to lead the key or the planner is handed a full scan wearing an
     * index's name.
     *
     * @return list<string>
     */
    private function pendingIndexColumns(ConnectionInterface $connection): array
    {
        return $connection->dialect()->supportsPartialIndexes()
            ? ['created_at']
            : ['published_at', 'dead_lettered_at', 'created_at'];
    }

    /**
     * Every column the outbox had before `dead_lettered_at` was added, in the order the
     * installer declared them.
     */
    private function legacyDefinition(): TableDefinition
    {
        return new TableDefinition(
            name: self::TABLE,
            columns: [
                new SchemaColumn(
                    name: 'event_id',
                    type: SchemaColumnType::String,
                    primaryKey: true,
                    length: 64,
                ),
                new SchemaColumn(name: 'event_type', type: SchemaColumnType::String, length: 255),
                new SchemaColumn(name: 'schema_version', type: SchemaColumnType::Integer),
                new SchemaColumn(name: 'payload_json', type: SchemaColumnType::Text),
                new SchemaColumn(name: 'payload_hash', type: SchemaColumnType::String, length: 128),
                new SchemaColumn(name: 'metadata_json', type: SchemaColumnType::Text),
                new SchemaColumn(
                    name: 'origin_module',
                    type: SchemaColumnType::String,
                    nullable: true,
                    length: 255,
                ),
                new SchemaColumn(name: 'scope', type: SchemaColumnType::String, length: 64),
                new SchemaColumn(
                    name: 'publish_attempts',
                    type: SchemaColumnType::Integer,
                    default: 0,
                    hasDefault: true,
                ),
                new SchemaColumn(name: 'last_error', type: SchemaColumnType::Text, nullable: true),
                new SchemaColumn(name: 'published_at', type: SchemaColumnType::DateTime, nullable: true),
                new SchemaColumn(name: 'created_at', type: SchemaColumnType::DateTime),
            ],
        );
    }

    private function envelope(string $type): EventEnvelope
    {
        return EventEnvelope::wrap(
            eventType: $type,
            schemaVersion: 1,
            payload: ['id' => 'x-1'],
            metadata: $this->metadata(),
            originModule: 'tests',
        );
    }

    /**
     * Fixed ids rather than generated ones, so an envelope built here is the same envelope
     * on every engine and every run.
     */
    private function metadata(): EventMetadata
    {
        return new EventMetadata(
            correlationId: CorrelationId::fromString('00000000000000000000000000000001'),
            causationId: CausationId::fromString('00000000000000000000000000000002'),
        );
    }

    /**
     * The pending index is narrowed to the rows the relay is looking for, where the engine
     * can express that.
     *
     * The predicate is the second thing about this index that nothing else in the file can
     * see. {@see IndexOperations::ensure()} decides existence by name, so an index compiled
     * without its `WHERE` clause has the right name over the right columns and merely covers
     * every row in a table that only grows — no query returns different rows because of it,
     * and no portable check the framework has can tell. Delete the `where:` arguments from
     * the migration and this assertion is the one that goes red.
     *
     * **MySQL cannot fail it, and that is the true answer rather than a gap.**
     * `MySqlDialect::supportsPartialIndexes()` is false and the dialect drops the predicate
     * before compiling, which is exactly why the migration puts the two filtered columns at
     * the head of the key on that engine instead — a claim
     * {@see pendingIndexColumns()} does assert there. Asserting the predicate is null on
     * MySQL says the dialect really did drop it rather than emitting something the engine
     * would have rejected.
     *
     * The two engines that keep it spell it back differently — SQLite verbatim from the
     * statement it was given, PostgreSQL normalised by `pg_get_expr()` into its own
     * parenthesised form — so what is asserted is what the predicate names, not its text.
     */
    private function assertPendingIndexIsNarrowed(ConnectionInterface $connection): void
    {
        $predicate = $this->indexPredicate($connection, self::TABLE, self::PENDING_INDEX);

        if (!$connection->dialect()->supportsPartialIndexes()) {
            self::assertNull(
                $predicate,
                'this engine has no partial indexes, so the dialect has to drop the predicate '
                . 'rather than compile something it would reject',
            );

            return;
        }

        if ($predicate === null) {
            self::fail(
                'the pending index has to be narrowed to what nobody has published yet, which on '
                . 'a healthy outbox is a small minority of a table that only grows. This engine '
                . 'has partial indexes and the index was built without one.',
            );
        }

        self::assertStringContainsString('published_at', $predicate);
        self::assertStringContainsString('dead_lettered_at', $predicate);
        self::assertStringContainsString(
            'IS NULL',
            $predicate,
            'both halves of the relay predicate are IS NULL tests; an index narrowed the other '
            . 'way covers exactly the rows the relay never reads',
        );
    }

    /**
     * The dead-letter index is narrowed the opposite way, and for the mirror-image reason:
     * dead-lettered envelopes are the rare rows, and triage is the only thing that reads
     * them.
     *
     * Same one-engine honesty as its sibling — MySQL drops the predicate and keeps only the
     * key, which is asserted above as `['dead_lettered_at']`.
     */
    private function assertDeadLetterIndexIsNarrowed(ConnectionInterface $connection): void
    {
        $predicate = $this->indexPredicate($connection, self::TABLE, self::DEADLETTER_INDEX);

        if (!$connection->dialect()->supportsPartialIndexes()) {
            self::assertNull(
                $predicate,
                'this engine has no partial indexes, so the dialect has to drop the predicate '
                . 'rather than compile something it would reject',
            );

            return;
        }

        if ($predicate === null) {
            self::fail(
                'triage reads the dead-lettered rows and nothing else, and this engine can say '
                . 'so in the index. The index was built without a predicate.',
            );
        }

        self::assertStringContainsString('dead_lettered_at', $predicate);
        self::assertStringContainsString(
            'IS NOT NULL',
            $predicate,
            'narrowed to the envelopes that have been dead-lettered, not to the ones that have '
            . 'not — the two predicates select disjoint halves of the table',
        );
    }

    /**
     * The statements from one pass that drop the pending index, whatever the engine spells
     * that as.
     *
     * Three spellings and one meaning: SQLite emits `DROP INDEX IF EXISTS "name"`, MySQL
     * `DROP INDEX \`name\` ON \`table\`` — it has no `IF EXISTS` and names the owning table —
     * and PostgreSQL wraps `DROP INDEX IF EXISTS` in a `DO` block that resolves the schema
     * first. Matching on both fragments rather than on a whole statement is what lets one
     * filter serve all three without transcribing any of them.
     *
     * @param list<string> $statements
     * @return list<string>
     */
    private function dropsOfThePendingIndex(array $statements): array
    {
        $drops = [];

        foreach ($statements as $statement) {
            if (str_contains($statement, 'DROP INDEX') && str_contains($statement, self::PENDING_INDEX)) {
                $drops[] = $statement;
            }
        }

        return $drops;
    }
}
