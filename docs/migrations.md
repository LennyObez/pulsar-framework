# Migrations

Pulsar migrations use timestamped PHP files containing anonymous classes. No naming collisions, zero boilerplate, version derived from filename.

## Creating migrations

```bash
php bin/pulsar migrate:create create_users_table
```

This generates a file like `database/migrations/20260203153000_create_users_table.php`:

```php
<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

// Either namespace works. The short form is an alias for the canonical one:
//   Pulsar\Database\MigrationInterface          (alias)
//   Pulsar\Database\Migration\MigrationInterface (canonical)
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $connection->execute('
            CREATE TABLE users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS users');
    }
};
```

## Filename convention

Three formats are accepted:

**Compact timestamp** (recommended for projects):

```
{YYYYMMDDHHMMSS}_description_snake_case.php
```

Example: `20260203153000_create_users_table.php`

**Separated timestamp** (Laravel-compatible):

```
{YYYY}_{MM}_{DD}_{HHMMSS}_description_snake_case.php
```

Example: `2026_02_03_153000_create_users_table.php`

**Sequential** (used by extensions):

```
{NNN}_description_snake_case.php
```

Example: `001_create_widgets_table.php`

Timestamp formats are recommended for application projects because they avoid ordering conflicts when multiple developers create migrations concurrently. The sequential format is used by framework extensions where migration order is fixed at release time.

### Version extraction and sorting

All three formats produce a 14-digit version string used for ordering:

- **Compact**: digits are used directly (`20260203153000`)
- **Separated**: segments are concatenated (`2026_02_03_153000` becomes `20260203153000`)
- **Sequential**: zero-padded to 14 digits (`001` becomes `00000000000001`)

The migration runner extracts the version with a regex: compact files match `^(\d{14})_`, separated files match `^(\d{4})_(\d{2})_(\d{2})_(\d{6})_`, sequential files match `^(\d{1,14})_`. Files matching none of these patterns are silently skipped.

Migrations are applied in ascending version order. Do not mix timestamp and sequential formats within a single migration directory.

## Where migrations come from

`migrate:run` does not read one directory. `MigrationPathResolver` collects three sources and the runner applies everything it finds, in ascending version order across all of them:

1. **The framework's own**, one directory per core module at `src/<Module>/Database/Migration/`. Nothing configures these and nothing needs to: they are found by glob, so a core module that gains migrations is picked up on the release that adds it. They carry the schema the framework itself requires — second-factor authentication, the transactional outbox, workflows, sagas, and the database failed-job repository.
2. **Your project's**, from `migrations.path` in `config/database.php`.
3. **Each extension's**, from the `provides.migrations` paths in its `pulsar.json`. An extension that failed to register or boot contributes none, so a broken extension cannot alter your schema on the way past.

Sequential versions are prefixed with a qualifier derived from the **name of the source that ships them** (`a3f2_00000000000001`), which is what keeps CMS `001_` and Forum `001_` from colliding. The names are `project`, `core:<Module>` and `ext:<extension>`. Timestamp versions take no prefix and are assumed globally unique — two directories offering the same timestamp stops the run rather than picking a winner.

### A version belongs to the migration, not to the checkout

The qualifier is a hash of that name and of nothing else, so the same migration has the same version on a laptop, in CI and on every deploy host.

Pulsar 1.0.0-rc.12 and earlier hashed the migration directory's **absolute path** instead. The same file was `a3f2_00000000000001` under `/home/dev/app` and `7b1c_00000000000001` under `/var/www/app`, so deploying identical code into a different path made every already-applied sequential migration read as pending — and `migrate:run` applied it a second time to the production database. Re-running a `CREATE TABLE` aborts the deploy; re-running an `ALTER` or a data backfill does worse.

If your database was written by rc.12 or earlier and any of your extensions use sequential filenames, see [Re-keying a table written before the change](#re-keying-a-table-written-before-the-change).

The framework's tables are migrations rather than something the framework creates for itself at start-up, and [ADR-0043](adr/0043-schema-belongs-to-migrations.md) records why: a runtime role that holds `CREATE` is a role that can also `DROP`, and a schema installed at boot leaves no answer to "who changed this, and when". The practical consequence is that **`migrate:run` is a deploy step, not an optional one** — a build that skips it fails at first use of whichever subsystem is missing its table.

## Running migrations

```bash
# Run all pending migrations
php bin/pulsar migrate:run

# Check migration status
php bin/pulsar migrate:status
```

## Rolling back

```bash
# Rollback the last batch
php bin/pulsar migrate:rollback

# Rollback ALL migrations (reset)
php bin/pulsar migrate:rollback --all
```

Each migration's `down()` runs in reverse version order, and its tracking row is removed only after `down()` returns. A `down()` that throws stops the run there: the migrations already reversed stay reversed and their rows are gone, the one that threw keeps its row and stays `Applied`, and everything below it is untouched. Running `migrate:rollback` again resumes with what is left, because those records still carry the batch number the run was working through.

### When a rollback refuses

**The five migrations that carry the framework's storage tables do not drop a populated table.** Versions `20260821000001` through `20260821000005` — `saga_states`, `workflow_instances` and `workflow_transitions`, `failed_jobs`, `outbox_events`, `saga_step_results` — count the rows first and throw instead of dropping when the count is not zero. (The older second-factor migration `20260327000001` predates the contract: its `down()` drops `auth_totp_secrets`, `auth_recovery_codes` and `auth_totp_replay_guard` whether or not anyone has enrolled.)

That is not caution, it is the difference between a reversal and a deletion. On a host that ran one of the storages' old `installSchema()` methods the table pre-dated its migration, so `up()` found it already there and created nothing — there is no create to reverse, and the `DROP` would only destroy integration events that a domain transaction committed and no relay has published yet, dead-lettered jobs nobody has triaged, or sagas whose compensation has not run. An empty table is the one state in which "put it back the way it was" and "delete the contents" are the same action, so that is the state the drop is conditional on.

The refusal reaches the console as the cause of the failure:

```
Rollback failed: Migration 20260821000004 (down) failed
Caused by: Refusing to drop outbox_events: it holds 3 row(s). Those are events already committed by a domain transaction and not yet published, plus any dead-lettered envelopes awaiting triage — dropping the table destroys them rather than reversing this migration. Drain the outbox, then roll back.
```

The command exits non-zero. `--all` behaves the same way — a reset stops at the first populated framework table rather than clearing the schema around it.

To get past it, empty the table through the subsystem that owns it, then run `migrate:rollback` again:

| Table                                        | How to empty it deliberately                                                                                                                                         |
| -------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `outbox_events`                              | Run `OutboxRelay::tick()` until nothing is pending, then resolve the dead-lettered envelopes it reports.                                                             |
| `failed_jobs`                                | Read the rows out with `FailedJobRepositoryInterface::all()` and keep what the audit trail needs, then `forget($id)` the ones you have handled or `flush()` the lot. |
| `saga_states`, `saga_step_results`           | Let the running sagas finish or compensate; archive the completed rows.                                                                                              |
| `workflow_instances`, `workflow_transitions` | Complete or cancel the live instances; archive the transition history, which is an audit record in its own right.                                                    |

A blind `DELETE` is available and is exactly what the refusal exists to make you think about first: the rows are the pending work, not a cache of it. If the rollback was aimed past this version at something else, there is no way around the stop — clear the table or leave the migration applied.

Nothing imposes this on your own migrations. It is a contract the framework keeps for its own tables, and worth copying in any `down()` whose rows outlive the schema change that created them.

## Batch system

Each `migrate:run` call assigns a batch number. All migrations applied in one run share the same batch. `migrate:rollback` rolls back only the most recent batch, in reverse version order.

Example:

| Version        | Name            | Batch |
| -------------- | --------------- | ----- |
| 20260101120000 | create_users    | 1     |
| 20260102120000 | create_posts    | 1     |
| 20260201120000 | add_user_avatar | 2     |

Running `migrate:rollback` rolls back only `20260201120000` (batch 2).

## Status

```bash
php bin/pulsar migrate:status
```

Output:

```
  Version           Name                                      Status      Batch    Applied At
  ----------------------------------------------------------------------------------------------------
  20260101120000    create_users                              Applied     1        2026-01-01 12:00:00
  20260102120000    create_posts                              Applied     1        2026-01-01 12:00:01
  20260201120000    add_user_avatar                           Pending    -        -
```

## Re-keying a table written before the change

`migrate:run` and `migrate:status` both refuse to proceed when the tracking table records a migration under a version this checkout no longer produces **and** the same migration is sitting on disk unapplied. That pairing has one cause — the version scheme changed under the table — and one consequence if ignored: the migration runs twice.

The refusal names every affected row and prints the statement that fixes it:

```
Migration identity mismatch. The migrations table "pulsar_migrations" records 3 migration(s)
under version strings this checkout no longer produces, and the same migrations are on disk
unapplied. Running now would re-apply them.

Sequential migration versions used to be qualified by a CRC32 of the absolute migrations
directory, so they changed whenever the checkout moved. They are now qualified by the name of
the source that ships them and are the same on every host.

Re-key these rows in a maintenance window, then re-run the migration:

    UPDATE pulsar_migrations SET version = '9b2c_00000000000001' WHERE version = 'a3f2_00000000000001';
    UPDATE pulsar_migrations SET version = '9b2c_00000000000002' WHERE version = 'a3f2_00000000000002';
    UPDATE pulsar_migrations SET version = '9b2c_00000000000003' WHERE version = 'a3f2_00000000000003';

Verify the updated row count against the number of statements before committing.
```

Run those statements yourself, in a transaction, in a maintenance window, against a database you have just backed up. Pulsar deliberately does not run them for you: re-keying rewrites the record of what has been applied to a production database, which is a reviewed operation and not a side effect of a deploy.

### When the pairing is ambiguous

Two extensions that both start at `001_` produce two rows with the same number, and which old row recorded which migration is not recoverable from the table. Those rows are listed separately, with their candidates:

```
These rows cannot be re-keyed automatically — several migrations on disk carry the same number,
so which one each row recorded is not recoverable from the table alone. Match them against the
source each came from:

    a3f2_00000000000001  ->  one of: 9b2c_00000000000001, 77aa_00000000000001
```

Resolve it from the `name` column of the same row — it holds the description from the filename (`create_cms_contents`, `create_forum_threads`) — and from `applied_at`, which orders the rows the way the extensions were installed. Then write the `UPDATE` by hand.

### What is not affected

- **Timestamp filenames.** They never carried a qualifier, so their versions are unchanged. A project using only `20260203153000_…` names has nothing to do.
- **A migration whose file you deleted after applying it.** It has no unapplied twin on disk, so it is not a renamed identity and does not stop the run.
- **A fresh database.** `db:fresh` drops every table, the tracking table with them, before it migrates — so there is nothing left to reconcile and nothing to refuse.

## Two sources must never ship one version

A timestamp version carries no source qualifier, so a version is the whole of a migration's identity in the tracking table — there is no column recording which source shipped a row. Two sources shipping `20260327000001` therefore write to the same row, and it costs one of two things depending on when they meet:

- **Both enabled at once**: discovery raises `Duplicate migration version: 20260327000001` and every command that discovers — `migrate:run`, `migrate:status`, `migrate:rollback`, `db:fresh` — stops there. Loud, and nothing migrates until it is resolved.
- **Enabled one after the other**: the row the first one wrote makes the second one's migration read as already applied. It is subtracted from the pending list, its tables are never created, and `migrate:run` reports nothing pending and exits 0.

The second is the dangerous one, so the runner refuses it too. Every applied row carries the `name` from the filename it was written by, and a row whose name is not the name of the migration now sitting at that version was written by a different migration:

```
Migration identity mismatch. The migrations table "pulsar_migrations" records 1 version(s)
under a different migration than the one now on disk at that version. Running would treat that
migration as already applied and never run it.

    20260327000001  recorded as "add_missing_fk_indexes", on disk as "create_health_check_history"
```

No `UPDATE` is offered, because which of the two migrations the row records decides the repair and only you can know: if it came from a source no longer installed, the migration on disk has never run and needs a version of its own before it can be applied; if you renamed the file after applying it, correct the row's `name` and stop renaming applied migrations.

Inside this repository the situation cannot arise at all: `ShippedMigrationVersionsAreUniqueTest` discovers every migration directory the framework and its bundled extensions ship — resolved, not listed, so a new extension is covered on the run that adds it — and fails on a version that appears twice. Give each migration a timestamp of its own, taken from when it was written; `00:00:01`, `00:00:02` used as sequence numbers is how three unrelated directories ended up on `20260327000001`.

## Migration tracking table

Pulsar creates a tracking table (default: `pulsar_migrations`) automatically. Schema is driver-aware:

- **SQLite**: `INTEGER PRIMARY KEY AUTOINCREMENT`
- **MySQL**: `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` with InnoDB
- **PostgreSQL**: `SERIAL PRIMARY KEY`

Columns: `id`, `version` (unique), `name`, `batch`, `applied_at`, `schema_version`.

`version` is `VARCHAR(30)`, which fits a 14-digit number and the four-character source qualifier that sequential versions carry.

## Configuration

In `config/database.php`:

```php
'migrations' => [
    'table' => 'pulsar_migrations',  // Tracking table name
    'path' => 'database/migrations', // Your project's migration files
],
```

`path` names your project's directory only. The framework's and each extension's are resolved separately and are not configurable — see [Where migrations come from](#where-migrations-come-from).

## Programmatic usage

```php
use Pulsar\Database\Migration\MigrationRunner;
use Pulsar\Database\Migration\MigrationRepository;

$repository = new MigrationRepository('database/migrations');
$runner = new MigrationRunner($connection, $repository, 'pulsar_migrations');

// A single path is a deliberate narrowing to that directory. To run what
// `migrate:run` runs, hand the repository every resolved path instead:
// new MigrationRepository($container->get(MigrationPathResolverInterface::class)->resolve())
//
// `resolve()` returns `source name => directory`, and the repository qualifies the
// sequential versions it finds under each directory with the name it was given. Passing a
// bare list of directories instead leaves them unqualified: fine for one directory, a
// duplicate-version error the moment two of them ship `001_`.

// Run pending
$applied = $runner->runPending();

// Rollback last batch
$rolledBack = $runner->rollbackLastBatch();

// Reset everything
$runner->reset();

// Get status
$applied = $runner->getApplied();
$pending = $runner->getPending();
$batch = $runner->getCurrentBatch();
```
