# ADR-0043: Schema belongs to migrations, not to the classes that read it

## Status

Accepted. Retires boot-time DDL framework-wide, which is a change of core-architecture
posture and so a change [ADR-0001](0001-ci-gates-and-adr-discipline.md) asks us to record
rather than absorb. Consumes the portable schema layer that
[ADR-0039](0039-schema-questions-belong-to-the-dialect.md) completed, and moves the
`Driver` ratchet [ADR-0037](0037-sql-server-as-a-fourth-driver.md) called for. The tables
themselves are the ones [ADR-0027](0027-workflow-saga-orchestration.md) introduced.
Breaking for any host that relied on the outbox table appearing by itself.

**Amended before release.** Two of the four divergences this ADR originally accepted were not
decisions at all: they were gaps in the vocabulary a portable migration had to describe a
table with, and the tables were narrowed to fit the layer rather than the layer widened to
fit the tables. Both are closed in `src/Database/Schema` — see decision 7 — and the list under
Consequences is shorter by them. Nothing else about the decision changed.

## Context

Five of the framework's own tables were created by the classes that read and wrote them.
Each storage carried an `installSchema()` method holding three hand-written dialects, and
the outbox carried a second, `migrateSchema()`, for an upgrade.

| Table                  | Installer                                                 | Production caller      |
| ---------------------- | --------------------------------------------------------- | ---------------------- |
| `saga_states`          | `DatabaseSagaStateStorage::installSchema()`               | none                   |
| `workflow_instances`   | `DatabaseWorkflowStorage::installSchema()`                | none                   |
| `workflow_transitions` | `DatabaseTransitionLog::installSchema()`                  | none                   |
| `failed_jobs`          | `DatabaseFailedJobRepository::installSchema()`            | none                   |
| `outbox_events`        | `DatabaseOutboxPort::installSchema()` + `migrateSchema()` | `EventWiring`, at boot |

**Four of the five installers had no caller, and the tables were bound anyway.**
`SagaWiring`, `WorkflowWiring` and `QueueWiring` are all in `WiringList::default()`, and
each binds its database storage as soon as a connection manager is bound: `SagaWiring`
binds `DatabaseSagaStateStorage` as `SagaStateStorageInterface`, `WorkflowWiring` binds
`DatabaseWorkflowStorage` and `DatabaseTransitionLog`, `QueueWiring` binds
`DatabaseFailedJobRepository`. Nothing called their `installSchema()`. So a durable saga
failed at `SagaOrchestrator::start()`, before it ran a single step, on a missing table —
and the same for workflows and for the failed-job repository. The only caller of
`installSchema()` anywhere in the repository was each storage's own unit test, which
created the table it then tested against. That is why the gap survived: the test that
should have caught it was the reason it did not.

`saga_step_results` was worse. It had no DDL anywhere, in any form — no installer, no
migration, not even a test fixture, because `DatabaseSagaStepResultStorageTest` runs
against a stub connection. `SagaWiring` binds `DatabaseSagaStepResultStorage`
unconditionally alongside the state storage, and `SagaOrchestrator` calls `record()`,
`markCompleted()` and `updateStatus()` on it.

**The one installer that did run, ran on the request path.** `EventWiring` called
`DatabaseOutboxPort::installSchema()` on every boot. Installing at boot means the
application executes DDL on every start, which means the deployment holds `CREATE` — and a
role that can `CREATE` can also `DROP` and `ALTER`. For a framework built for banking and
healthcare that is the wrong posture; least privilege is not a preference. It turns a
code-execution or deserialisation finding from a data-read problem into a schema problem,
and it removes the separation an auditor expects between the role that changes the schema
and the role that reads and writes rows. Asked "who changed this schema, and when", such a
deployment can only answer "the application, at some point, on its own authority". It also
put a `CREATE TABLE IF NOT EXISTS` race on the start-up of every FPM child, every worker
and every console invocation — a metadata lock per boot for a statement whose answer is
"already done" on all but the first — and recorded nothing, so `migrate:status` could not
say whether a database was the shape the build expected.

**Keeping `installSchema()` beside a migration would leave two definitions of one table.**
Nothing would compare them. `MigrationRepository` tracks migrations by path and version;
its only hash is a CRC32 over the _directory_ path, used to bucket extension migration
sets, and it never reads file contents. And `CREATE TABLE IF NOT EXISTS` is silent on an
existing host by design, so a table already installed in the other shape passes the
migration without complaint. A drift between the two definitions would therefore be
undetectable in exactly the way that matters — per host, at deploy time, with every host
reporting the migration applied.

`migrateSchema()` is the proof that this is not hypothetical. It existed to add
`dead_lettered_at` to outbox tables that predated the column. It had no production caller,
and the only path that reached it was a test running against a table `installSchema()` had
already created the column on. So the upgrade "passed" everywhere and executed nowhere, and
anyone who installed before dead-lettering is still missing the column today.

## Decision

**1. The installers are deleted, not deprecated.** `installSchema()` and `migrateSchema()`,
with every per-dialect helper they called, are removed from all five classes. Leaving one in
place beside a migration is what lets the two definitions drift, and the one that drifts is
whichever the tests do not exercise. Keeping them "as a convenience for local development"
is the same arrangement under a friendlier name, and it is the arrangement that produced
`migrateSchema()`.

**2. Five migrations own the five tables, plus the one that never had DDL at all.**

- `src/Workflow/Database/Migration/20260821000001_create_saga_states_table.php`
- `src/Workflow/Database/Migration/20260821000002_create_workflow_tables.php`
- `src/Queue/Database/Migration/20260821000003_create_failed_jobs_table.php`
- `src/Event/Database/Migration/20260821000004_create_event_outbox_table.php`
- `src/Workflow/Database/Migration/20260821000005_create_saga_step_results_table.php`

**3. `EventWiring` installs nothing.** It binds a reader and writer of `outbox_events` and
stops there. The obvious alternative — repair the four silent failures by calling
`installSchema()` from `SagaWiring`, `WorkflowWiring` and `QueueWiring` too — was rejected
on the terms above: it fixes a missing table by making four more deployments run DDL at
boot under a role holding `CREATE`. A missing table now fails loudly on first use, and
points at the deploy step nobody ran.

**4. No migration names an engine.** Each table is described once as a `TableDefinition` of
`SchemaColumn` values and compiled per engine by `DdlCompiler`; index work goes through
`IndexOperations` and existence questions through `TableIntrospector`, with narrowing chosen
by `SchemaCapabilities` rather than by engine name. There are zero occurrences of `Driver::`
across all five files. A caller that writes `match ($driver)` has taken on knowing every
engine the framework will ever support, which is what makes adding one a breaking change;
`DriverDispatchRatchetTest` enforces the retirement as a ratchet, and deleting the installers
moved it from 73 files to 70 — `DatabaseOutboxPort`, `DatabaseTransitionLog` and
`DatabaseWorkflowStorage` no longer name an engine at all. (An earlier draft of this ADR read
83 and 80, quoting the baseline's `count` field; that field had drifted ten above the list it
summarised and is now asserted against it. `tests/Unit/Integrity/driver-dispatch-baseline.json`
reads 69 today, not 70: `ComplianceVerificationWiring` stopped dispatching on the driver in the
same release, under a change unrelated to this one.) `DatabaseSagaStateStorage` and
`DatabaseFailedJobRepository` remain on the baseline, for upsert DML rather than for DDL.

**5. `down()` refuses rather than destroys.** On a host that had run `installSchema()` the
table pre-existed, so `up()` was a no-op, and dropping the table would be destruction rather
than reversal — of unfinished sagas with compensation still owed, of committed-but-
unpublished events, of dead-letter audit records, of the only record of which compensations
ran. Every `down()` therefore drops a table only when it is empty and otherwise raises,
naming the table and its row count. A fresh install — the only case where rolling back is
meaningful — still reverses cleanly. Dropping unconditionally reads as symmetry and is not:
where `up()` changed nothing, a drop reverses nothing and destroys everything the table held.

**6. Tests obtain schema by running the real migration file.** `tests/Support/FrameworkSchema`
requires the file and calls `up()`; `tests/Contract/SchemaMigrationContractTestCase` and its
five subclasses execute each migration against every configured engine. Re-declaring the DDL
inline in a test is specifically rejected, and
`TotpReplayGuardMigrationContractTest` records why: a suite that did that passed on all three
engines against a migration that threw on MySQL, because the inline copy had omitted the only
method that failed.

Running the real file is necessary and is not sufficient. A test that creates the table and
then reads rows back through the storage cannot see a column's type or a table's collation:
on MySQL every one of those reads passes against a `TEXT` column it will one day overflow,
and against the case-insensitive collation the server defaults to. So
`SchemaMigrationContractTestCase` asks the engine's own catalogue — `DATA_TYPE` from
`information_schema.columns` on MySQL, `data_type` on PostgreSQL, `pragma_table_info` on
SQLite; `TABLE_COLLATION` and the per-column `COLLATION_NAME` for the comparison rule — and
separately asserts the behaviour those settings exist to buy: a 70,000-byte payload written
and read back whole, and `AB` and `ab` surviving as two rows under their own keys. The
catalogue half and the behavioural half are separate test methods deliberately; inside one
method, whichever assertion ran first would be the only one a regression ever reached and the
other would be decoration. Each was verified by deleting the repair it guards and watching
the suite go red on the engine where the repair does the work.

**7. The two divergences with teeth were vocabulary gaps, so the vocabulary was widened.**
The list under Consequences used to open with two entries that cost data rather than spelling:
MySQL's `LONGTEXT` narrowed to `TEXT`, and the installer's table options dropped whole.
Neither was a decision anyone wanted to make. `SchemaColumnType` held one text type and
`TableDefinition` held no table options at all, so a migration written against the portable
layer could not say what the hand-written DDL had said, and the tables were narrowed to fit
the layer. Both gaps are now closed in `src/Database/Schema`, which is where a gap ten columns
and six tables share belongs: repairing it at one call site with `match ($driver)` would have
fixed one table and re-imported the debt decision 4 exists to retire.

- **`SchemaColumnType::BigText`** compiles to `LONGTEXT` on MySQL and to `TEXT` on PostgreSQL
  and SQLite, where `TEXT` is already the widest either engine has. `Text` keeps its meaning
  and is still right for text a person typed into a field somebody sized; `BigText` is for a
  value whose width the data decides. Ten columns take it — `saga_states.step_results` and
  `.context`, `workflow_instances.context`, `workflow_transitions.metadata`,
  `failed_jobs.payload` and `.exception`, `outbox_events.payload_json` and `.metadata_json`,
  `saga_step_results.result_data` and `.error_message`. Two neighbours deliberately keep
  `Text` — `outbox_events.last_error` and `workflow_transitions.reason` — because the
  installer had them narrow and a 64 KiB ceiling on an operator's note is a bound, not a bug.
- **`SchemaCollation`**, set on a `TableDefinition` or on a single `SchemaColumn`, states how
  a table compares the text it stores. Its one case, `Exact`, emits `COLLATE=utf8mb4_bin` on
  MySQL and nothing on PostgreSQL and SQLite, which already compare exactly. All six tables
  ask for it, because all six are keyed on identifiers.

**The enum has one case, and the missing one is missing on purpose.** `Exact` is an intent all
three engines can honour: MySQL by naming `utf8mb4_bin`, the other two by saying nothing,
because a deterministic collation and SQLite's `BINARY` are that same byte comparison already.
A case-insensitive counterpart has no such answer. PostgreSQL has no table-level spelling for
it — it needs a non-deterministic ICU collation created per database, or the `citext`
extension — and SQLite's `NOCASE` folds ASCII `a`–`z` only, so `Ä` and `ä` stay distinct under
it. Offering the case would mean accepting a request the layer can honour on one engine of
three and approximate on the other two, which is the exact failure this ADR was written
about: one fleet running two shapes of one table with every host reporting success. The two
directions are not symmetric either. A caller who asks for `Exact` and does not get it loses
rows to a key collision; a caller who wants case-insensitive matching and has no vocabulary
for it writes `LOWER(col) = LOWER(:v)` in the query or adds a generated column, where the
folding is visible at the point it applies and the engines' disagreement is the caller's to
see.

## Consequences

**Deployments need a migrate step, and this is a breaking change for the outbox.** A host
that enabled the outbox got its table for free; it no longer does. Anything relying on that
must now run `pulsar migrate`, and `docs/events.md` says so where it previously said the
schema was auto-installed. A host upgrading from a build that installed at boot already has
the tables and `up()` leaves them alone; a fresh install that skips the migrate step gets a
loud failure at first use instead of a schema built by whichever process booted first.

**`down()` refusing to drop a non-empty table is not the conventional migration contract,
and is deliberate.** A `down()` that is usually a data-loss event is one operators learn not
to run, which makes the rollback path untested at the moment it is needed. Refusing where
reversal is impossible and succeeding where it is possible keeps the one case that matters
honest.

**Four subsystems that could not have worked now can.** Durable sagas, workflows, the
transition log and the failed-job repository all have DDL for the first time, and
`saga_step_results` exists at all for the first time.

**The outbox dead-letter upgrade finally executes.** It is folded into `up()` of
`20260821000004` behind its own postcondition — the absence of the column — rather than
behind the same branch as the index work, so a run that dies after the ALTER commits still
completes the indexes on replay. Because `IndexOperations::ensure()` decides existence by
name, a legacy host's `outbox_events_pending_idx`, compiled before `dead_lettered_at`
existed, would have been left in the wrong shape by a bare `ensure()`; the migration drops
it first, keyed on the absence of the dead-letter index — a durable fact about the table
rather than a flag an earlier statement in the same run invalidates.

That guard is asserted rather than assumed, and it takes an unusual test to do it. An
unguarded second pass drops and rebuilds an index that was already correct, so the database
afterwards is byte for byte what a guarded run leaves and no catalogue question can separate
the two; only the statements differ. `EventOutboxMigrationContractTest` therefore drives
`up()` through a `RecordingConnection` and requires the first pass over a legacy table to
issue exactly one `DROP INDEX` naming the pending index, and the second to issue no statement
at all. What the rebuilt index carries is read back from the catalogue on the engines that
have one to read — `sqlite_master` on SQLite, `pg_get_expr(indpred, indrelid)` on PostgreSQL.
MySQL has no partial index, so `IndexOperations` puts the filtered columns at the head of the
key there instead, and that substitution is asserted in its place rather than skipped.

**Three differences from the installed shape are accepted, and land on fresh installs only.**
`up()` does not touch a table that already exists, so no host changes shape underneath
itself. They are stated here rather than left to be discovered, because a silent divergence
is how one fleet comes to run two incompatible shapes of one table with every host reporting
the migration applied. This list had four entries. The first is gone and the second is
reduced to the one clause that really is absent, because decision 7 supplied the vocabulary
both of them were missing.

- **`ENGINE=InnoDB` is the one table option not emitted, and nothing follows from it.** Of
  the installer's trailing `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin`, the
  collation is now emitted from the `TableDefinition` and the character set does not need to
  be: MySQL derives a table's character set from its collation when only the collation is
  given, and `utf8mb4_bin` belongs to utf8mb4. `ENGINE=InnoDB` really is absent, and it
  changed nothing — InnoDB has been the default since MySQL 5.5 and, as its XtraDB fork,
  since MariaDB 10.0, under its own name there since 10.2 — and a
  server configured to default elsewhere would be creating non-transactional tables for the
  whole application rather than for these six, which is a connection-level fault a per-table
  clause cannot rescue. It never bought a row format either: `ROW_FORMAT` comes from
  `innodb_default_row_format` or an explicit clause, not from the engine name.
- **`TEXT` key columns become bounded `VARCHAR` on PostgreSQL and SQLite.** MySQL cannot key
  a `TEXT` column without a prefix length, so one type has to serve all three and it has to be
  the bounded one. The widths are the ones MySQL already enforced. PostgreSQL now rejects an
  over-long value with 22001 where it used to store it; SQLite does not enforce lengths, so
  nothing changes there.
- **`PRIMARY KEY` columns gain `NOT NULL` on SQLite.** A `PRIMARY KEY` column in a SQLite
  rowid table is nullable, a legacy behaviour SQLite preserves on purpose. The compiler
  declares it `NOT NULL`, which can only reject a row the other two engines already rejected.

**A host that already has a table keeps the shape it has, column types and collation
included.** `up()` returns the moment `TableIntrospector` finds the table, and nothing in
these five files alters the type, the width or the comparison rule of a column that already
exists; the single exception is the one named above, `20260821000004` adding
`outbox_events.dead_lettered_at` and rebuilding the pending index. The shapes described here
are therefore what a fresh install gets, and an adopting host gets what it already had. That
is the right default — a migration that silently retyped a live column would rebuild the
whole table on MySQL, under a lock, in the middle of a deploy — but it means the fresh-install
shape and the adopted shape can differ, and the difference is now in the direction that
matters: the fresh install is the wide, byte-exact one. For the only one of the six a Pulsar
deployment ever created for itself, the two converge on their own — `outbox_events` came from
`DatabaseOutboxPort::installSchema()`, whose MySQL branch already wrote `LONGTEXT` and
`COLLATE=utf8mb4_bin`. A table that reached a host some other way may not have, so
[`docs/upgrade.md`](../upgrade.md#framework-schema-now-comes-from-migrations) states what an
operator on such a host keeps, what it costs, and the `ALTER` that converges it.

**No public API break.** All five classes carry `#[Internal]`, never `#[Api]`:
`DatabaseSagaStateStorage`, `DatabaseWorkflowStorage` and `DatabaseTransitionLog` name their
replacement port in the attribute; `DatabaseFailedJobRepository` and `DatabaseOutboxPort` are
bare `#[Internal]`. `tools/api/public-api.snapshot.json` lists internal classes by name only
and records no method signatures for them, so removing the methods does not move the
snapshot. Applications reach these through `SagaStateStorageInterface`,
`WorkflowStorageInterface`, `TransitionLogInterface`, `FailedJobRepositoryInterface` and
`OutboxPort`, none of which ever declared `installSchema()`.

The snapshot does move for decision 7, and only additively: `SchemaColumnType::BigText`,
the `SchemaCollation` enum, `Blueprint::bigText()`, `Blueprint::collation()`,
`ColumnBuilder::collation()` and the trailing `collation` parameter on `TableDefinition` and
`SchemaColumn`. Every existing spelling means what it meant — `SchemaColumnType::Text` still
compiles to `TEXT` on all three engines, and a definition that names no collation still emits
no clause — so a migration written against the previous release compiles to the same DDL.

**One instance of the pattern is out of scope.** `extensions/auth`'s
`DbAuthorizationCodeRepository::installSchema()`, called from `AuthServiceProvider`, is the
same posture in an extension. It is not touched here and should follow under its own change.
