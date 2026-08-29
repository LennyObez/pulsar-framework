# Upgrade guide: 0.x to 1.0.0-rc.11

This guide covers the migration path from Pulsar 0.x (pre-alpha/alpha) to 1.0.0-rc.11. The RC series marks the release candidate phase with a formal public API surface and semver guarantees.

## What 1.0.0-rc.11 means

This is a release candidate. The public API is frozen and covered by semantic versioning guarantees. No breaking changes will be introduced between rc.8 and the final 1.0.0 release unless a critical defect is discovered.

Bug fixes and documentation improvements may land before 1.0.0 final. New features will not.

## Breaking changes summary

### API stability attributes

All framework classes are now annotated with `#[Api]` or `#[Internal]` attributes. This is the most significant architectural change in 1.0.0.

- **`#[Api]`**: Marks a class, method, or class constant as part of the public API. Breaking changes to these types require a major version bump.
- **`#[Internal]`**: Explicitly marks a class as internal. This attribute is optional because everything without `#[Api]` is internal by default. It is used for emphasis on types that users might mistakenly depend on.

If your code depends on any class not marked `#[Api]`, you are depending on an internal implementation detail that may change in any minor release. Review the [Public API Reference](public-api.md) for the complete list.

### What is covered by semver guarantees

The following are covered and will not break without a major version bump:

- All classes, interfaces, enums, and readonly DTOs annotated with `#[Api]`.
- Method signatures on public API types (parameter types, return types, names).
- Class constants on public API types.
- The `pulsar.json` manifest schema.
- CLI command names and their documented options/arguments.
- Configuration DTO constructors and their `fromArray()` factory methods.

### What is internal and may change

The following are not covered by semver and may change in minor releases:

- Classes annotated with `#[Internal]` or lacking an `#[Api]` annotation.
- The 10 explicitly internal classes: `Kernel`, `Version`, `ConfigManager`, `ConfigOverrides`, `Application` (Console), `ExtensionRegistry`, `ExtensionBootstrap`, `ExtensionLoader`, `MiddlewarePipeline`, `MiddlewareRegistry`.
- Private and protected methods on any class.
- Internal exception message wording (exception types and factory methods are stable).
- Performance characteristics (budgets are targets, not guarantees).
- Debug output format and diagnostic display layout.

### Namespace changes

No namespaces were renamed in 1.0.0-rc.11. All classes remain under the `Pulsar\` root namespace. If you are upgrading from 0.2.x or earlier, the following namespaces were added in the 0.3.0-0.9.0 series:

- `Pulsar\Api`: API stability attributes (added in 1.0.0).
- `Pulsar\Auth`: Authentication, authorization, identity, 2FA (added in 0.8.0).
- `Pulsar\Database`: Database abstraction, connections, migrations (added in 0.7.0).
- `Pulsar\ErrorHandling`: Exception rendering, HTTP exceptions (added in 0.3.0).
- `Pulsar\FeatureFlag`: Feature flag management (added in 0.9.0).
- `Pulsar\Observability`: Logging, metrics, tracing, error tracking (added in 0.5.1).
- `Pulsar\Resilience`: Health checks, circuit breaker, retry, self-healing (added in 0.9.0).
- `Pulsar\Scheduler`: Job scheduling (added in 0.9.0).
- `Pulsar\Security`: Sessions, CSRF, audit logging (added in 0.6.0).
- `Pulsar\Tenancy`: Multi-tenancy (added in 0.9.0).

### Configuration system

Configuration DTOs are now all `readonly` classes with `fromArray()` static factory methods. If you were constructing config objects manually, update to use the constructor directly or `fromArray()`.

Before (0.x pattern with arrays):

```php
$config = ['driver' => 'mysql', 'host' => 'localhost'];
```

After (1.0.0 pattern with typed DTOs):

```php
use Pulsar\Config\DatabaseConfig;

$config = DatabaseConfig::fromArray([
    'driver' => 'mysql',
    'host' => 'localhost',
]);
```

### Extension manifest schema

The `pulsar.json` manifest schema is stable. If you wrote extensions against 0.2.0, the schema has gained fields but remains backward compatible:

- `provides.middleware` was added (optional, defaults to empty array).
- `requires` was added for extension dependencies (optional, defaults to empty object).
- `pulsar.max_version` was added (optional).

Existing manifests without these fields continue to work.

### Container interface

`Pulsar\Container\ContainerInterface` is PSR-11 compatible. The `get()` and `has()` methods are unchanged. Binding methods (`bind`, `singleton`, `factory`, `instance`) are stable.

### HTTP layer

`Request` and `Response` are immutable value objects. Static factory methods on `Response` (`::json()`, `::html()`, `::text()`, `::redirect()`) are stable API. `HeaderBag` is stable. The `Method` and `ResponseStatus` enums are stable.

### Routing

`Router`, `Route`, `RouteGroup`, and `MatchedRoute` are stable API. Route registration methods (`get`, `post`, `put`, `patch`, `delete`, `any`, `group`) have not changed signatures.

### Framework schema now comes from migrations

Five of the framework's own tables used to be created by an `installSchema()` method on the storage class that read them. Those methods are deleted and the tables ship as framework migrations, applied by `migrate:run` ([ADR-0043](adr/0043-schema-belongs-to-migrations.md)):

| Table                                        | Migration                                           |
| -------------------------------------------- | --------------------------------------------------- |
| `saga_states`                                | `20260821000001_create_saga_states_table.php`       |
| `workflow_instances`, `workflow_transitions` | `20260821000002_create_workflow_tables.php`         |
| `failed_jobs`                                | `20260821000003_create_failed_jobs_table.php`       |
| `outbox_events`                              | `20260821000004_create_event_outbox_table.php`      |
| `saga_step_results`                          | `20260821000005_create_saga_step_results_table.php` |

**This is breaking for the transactional outbox, and only for it.** `EventWiring` called `DatabaseOutboxPort::installSchema()` on every boot, so a deployment with `outbox.enabled` set got `outbox_events` for free. It no longer does: run migrations before the new build serves its first request, or `store()` fails on a missing table. The other four installers had no production caller, so those tables were never created for anyone — the migrations are the first DDL those subsystems have ever had, and `saga_step_results` had none in any form.

Applying these to a host that already has the tables leaves those tables exactly as they are. `up()` asks `TableIntrospector` whether the table exists and returns if it does, so nothing in the five files alters the type, the width or the collation of a column that is already there. One change is intended and does reach an existing table: `20260821000004` adds `outbox_events.dead_lettered_at` where it is absent and rebuilds the pending index that was compiled before that column existed. That upgrade previously lived in a `migrateSchema()` method nothing ever called, so a deployment that installed before dead-lettering has never received it.

Two consequences worth planning for: the runtime database role no longer needs `CREATE`, and `down()` on these migrations refuses to drop a table that holds rows rather than destroying unpublished events or sagas with compensation still owed.

#### What an adopted table does not get, and what that costs

"Leaves them as they are" cuts both ways. A fresh install creates these tables with `LONGTEXT` for every column holding a serialized value (`SchemaColumnType::BigText`) and `COLLATE=utf8mb4_bin` on the table (`SchemaCollation::Exact`). A host that already has the table gets neither, because `CREATE TABLE` never runs for it — and the migration is recorded as applied either way, so `migrate:status` cannot tell you which of the two shapes a host is on. Only the database can.

For the one framework table a Pulsar deployment ever created for itself, this costs nothing. `outbox_events` came from `DatabaseOutboxPort::installSchema()`, whose MySQL branch already wrote `payload_json LONGTEXT`, `metadata_json LONGTEXT` and `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin`; the migration reproduces that shape rather than narrowing it, so on MySQL an adopting host already has both of the things this section is about. (It can still differ from a fresh install in ways that cost nothing here — on PostgreSQL and SQLite the installer's key columns were unbounded `TEXT` where a fresh install writes `VARCHAR`; [ADR-0043](adr/0043-schema-belongs-to-migrations.md) lists those.) The other four installers had no production caller, so those tables are created by the migration and are the new shape by construction.

The case that needs a decision is a table that reached the host some other way — hand-written DDL, a dump restored from an older system, a table someone created by hand to unblock a deploy. It keeps whatever it has, and on MySQL that is two specific risks:

- **A narrow `TEXT` payload column stops at 65,535 bytes** — bytes, not characters, so a `utf8mb4` column runs out somewhere between 16,383 and 65,535 characters. Under MySQL's shipped `sql_mode` an oversized write raises error 1406 and the caller sees it refused inside its own transaction; with strict mode switched off the value is truncated instead and the stored JSON no longer parses on the next read.
- **A case-insensitive table collation makes two ids differing only in case one row.** MySQL's defaults — `utf8mb4_0900_ai_ci` on 8.0, `utf8mb4_general_ci` on MariaDB — fold case and accents, and every one of these tables keys on an identifier. Ids the framework issues itself are `bin2hex()` over random bytes, lower-case hex that cannot collide that way; ids an application supplies can. It fails silently rather than loudly, because these storages upsert: the second write overwrites the first instead of raising.

Which columns are wide on a fresh install:

| Table                  | `LONGTEXT` on MySQL             | Deliberately narrow |
| ---------------------- | ------------------------------- | ------------------- |
| `saga_states`          | `step_results`, `context`       | —                   |
| `workflow_instances`   | `context`                       | —                   |
| `workflow_transitions` | `metadata`                      | `reason`            |
| `failed_jobs`          | `payload`, `exception`          | —                   |
| `outbox_events`        | `payload_json`, `metadata_json` | `last_error`        |
| `saga_step_results`    | `result_data`, `error_message`  | —                   |

All six tables are created `COLLATE=utf8mb4_bin` on MySQL.

**Keeping the narrow shape is a supported outcome.** If your ids come from the framework and your payloads stay under 64 KiB, the adopted table behaves identically to the fresh one and there is nothing to do. Decide it per host on evidence rather than on assumption:

```sql
-- MySQL: the type and comparison rule the table actually has
SELECT COLUMN_NAME, COLUMN_TYPE, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbox_events';

SELECT TABLE_COLLATION FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbox_events';
```

**To converge on the fresh-install shape, issue the `ALTER` yourself.** No migration does it for you, and that is deliberate: retyping a live column is a table rebuild, which belongs in a maintenance window rather than in the middle of a deploy.

```sql
-- MODIFY restates the whole column definition, so repeat NOT NULL or you drop it.
ALTER TABLE outbox_events
    MODIFY payload_json  LONGTEXT NOT NULL,
    MODIFY metadata_json LONGTEXT NOT NULL;

-- Every character column in the table, plus the table default.
ALTER TABLE outbox_events CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
```

Three things to plan for:

- **Both statements copy the table.** InnoDB cannot change a column's type in place, so the rebuild takes a lock and blocks writes for its duration. Size the window against the table, and drain the outbox relay or the queue worker first.
- **The collation change cannot fail on existing rows.** Moving from a case-insensitive collation to an exact one only separates values that were previously treated as equal; it never merges two rows, so no `PRIMARY KEY` can be violated by the conversion. The reverse direction can, which is part of why the framework offers no case-insensitive counterpart.
- **`CONVERT TO CHARACTER SET` is a collation-only change when the table is already utf8mb4.** On a table still on `latin1` it re-encodes every value and can promote `TEXT` to `MEDIUMTEXT` to preserve the byte length. That is a data migration, not a schema tweak — rehearse it on a copy.

PostgreSQL and SQLite need none of this. `Text` and `BigText` both compile to `TEXT` there, which is already the widest either engine has, and both compare text exactly by default, so `SchemaCollation::Exact` emits nothing on them because there is nothing to say.

## Migration steps checklist

### 1. Update Composer dependency

```bash
composer require pulsar/framework:^1.0.0-rc.11
```

### 2. Audit internal dependencies

Search your codebase for imports of internal classes:

```bash
grep -r "use Pulsar\\Core\\Kernel" app/
grep -r "use Pulsar\\Config\\ConfigManager" app/
grep -r "use Pulsar\\Extensibility\\ExtensionRegistry" app/
grep -r "use Pulsar\\Extensibility\\ExtensionBootstrap" app/
grep -r "use Pulsar\\Extensibility\\ExtensionLoader" app/
grep -r "use Pulsar\\Http\\Middleware\\MiddlewarePipeline" app/
grep -r "use Pulsar\\Http\\Middleware\\MiddlewareRegistry" app/
grep -r "use Pulsar\\Console\\Application" app/
grep -r "use Pulsar\\Config\\ConfigOverrides" app/
grep -r "use Pulsar\\Core\\Version" app/
```

If you depend on any of these, refactor to use the public API equivalents or accept that those usages may break in future minor releases.

### 3. Update configuration files

Compare your `config/` files against the latest stubs:

```bash
diff config/app.php vendor/pulsar/framework/config/app.php
diff config/security.php vendor/pulsar/framework/config/security.php
diff config/database.php vendor/pulsar/framework/config/database.php
```

New configuration files added since 0.2.0:

- `config/features.php`: Feature flag definitions.
- `config/resilience.php`: Health checks, circuit breaker, retry policies.
- `config/scheduler.php`: Scheduled job configuration.
- `config/tenancy.php`: Multi-tenant settings.

### 4. Update extension manifests

Ensure all `pulsar.json` manifests specify a `min_version` compatible with 1.0.0:

```json
{
  "pulsar": {
    "min_version": "1.0.0"
  }
}
```

### 5. Run migrations

```bash
php bin/pulsar migrate:run
```

This applies the framework's own migrations alongside your project's and your extensions' — see [Where migrations come from](migrations.md#where-migrations-come-from). On a host that already has the tables it is close to a no-op, and the shape of those tables is left alone rather than upgraded; on a fresh one it is what creates them, in the wider and byte-exact shape. If you use the transactional outbox, read the [breaking-change note](#framework-schema-now-comes-from-migrations) above before deploying: that step is what keeps the first request of the new build from failing on a missing table, and [What an adopted table does not get](#what-an-adopted-table-does-not-get-and-what-that-costs) is what to check afterwards on a host whose tables predate it.

### 6. Run diagnostics

```bash
php bin/pulsar diagnostics
```

Verify that all extensions load and all required PHP extensions are available.

### 7. Run tests

```bash
composer test
```

Fix any type errors or API changes surfaced by your test suite.

### 8. Run static analysis

```bash
php -d memory_limit=512M vendor/bin/phpstan analyse -c tools/php/phpstan.neon
vendor/bin/psalm -c tools/php/psalm.xml
```

PHPStan (level max) and Psalm (error level 1) may flag new issues from stricter typing in 1.0.0.

## Behavioural changes admitted under the critical-defect clause

The RC series freezes the public API, with one stated exception: a critical defect.
The following change is behavioural rather than additive, and is recorded here
because it affects an `#[Api]` type.

### `RetentionPolicy::fromArray()` refuses malformed entries (was: silently defaulted)

`data_protection.retention` entries were parsed with silent fallbacks, and every
one of those fallbacks meant _retain this data forever_:

- `retention_days` accepted only a real integer. Because `env()` returns strings,
  the ordinary `'retention_days' => env('RETENTION_DAYS', 90)` produced `"90"`,
  which fell back to `0` — and `0` means indefinite retention. An operator who
  configured a 90-day period got data kept forever, with no warning anywhere. For
  personal-data categories that is a storage-limitation violation (GDPR Art.
  5(1)(e)) caused by a supported configuration idiom.
- A non-string or missing `category` became `''`, which matches no purger, so those
  records were never purged either.
- A negative period was clamped to `0`, i.e. indefinite again.

`fromArray()` now accepts any numeric value (integer, float or numeric string) and
throws `ConfigException` when a value is _present but unreadable_, when `category`
is blank, or when the period is negative. An **absent** `retention_days` still
means `0` = indefinite, which remains the documented, deliberate way to retain
without expiry.

`DefaultRetentionPolicy::fromArray()` now delegates to the same parser, so the two
classes can no longer disagree about the same config entry.

**What to do:** nothing, if your `config/data_protection.php` uses integer literals
or `env()` values that are numeric — those parse as before, or now parse correctly.
If boot throws, the message names the exact config path and the reason; fix the
value rather than restoring the old behaviour, which was silently retaining data.

## Deprecation notices

No formal deprecations exist in 1.0.0-rc.11. The `#[Api]` / `#[Internal]` boundary replaces the informal "probably stable" / "probably internal" convention used in 0.x.

Classes that were commonly used in 0.x but are now marked `#[Internal]` should be treated as deprecated for external use. These include `Kernel`, `Version`, and `ConfigManager`. Use the public API surface documented in [public-api.md](public-api.md) instead.

## Getting help

- Run `php bin/pulsar diagnostics` to verify your environment.
- Run `php bin/pulsar list` to see all available commands.
- Review the [Public API Reference](public-api.md) for semver-stable types.
- Review the [Extensions Guide](extensions.md) for extension migration details.
