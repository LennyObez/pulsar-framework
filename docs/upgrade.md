# Upgrade guide: 0.x to 1.0.0-rc.12

This guide covers the migration path from Pulsar 0.x (pre-alpha/alpha) to 1.0.0-rc.12. The RC series marks the release candidate phase with a formal public API surface and semver guarantees.

Coming from rc.11 rather than from 0.x? Read [Upgrading from 1.0.0-rc.11 to 1.0.0-rc.12](#upgrading-from-100-rc11-to-100-rc12) and stop there; the rest of this guide is the 0.x migration.

## What 1.0.0-rc.12 means

This is a release candidate. The public API is frozen and covered by semantic versioning guarantees: `#[Api]` types take additive change during the RC series, and a change that is not additive needs a critical defect behind it.

That exception has been used, and this guide records each use rather than leaving the freeze stated as though it were absolute. Two sections carry them: [Upgrading from 1.0.0-rc.11 to 1.0.0-rc.12](#upgrading-from-100-rc11-to-100-rc12) for this release, and [Behavioural changes admitted under the critical-defect clause](#behavioural-changes-admitted-under-the-critical-defect-clause) for the ones before it.

Bug fixes and documentation improvements may land before 1.0.0 final. New features will not.

## Upgrading from 1.0.0-rc.11 to 1.0.0-rc.12

rc.12 publishes the compliance control engine as public API: `Pulsar\Compliance\Control\*`, `Pulsar\Compliance\Probe\*` and `Pulsar\Compliance\Evidence\*` are marked `#[Api]` since 1.0.0-rc.12. None of those types appear in the rc.11 API snapshot, so **if you are coming from a released rc.11 they are additions, and nothing in the three sections below can break your code**.

They do break code written against an rc.12 pre-release — a `dev-` requirement, or a checkout of the release branch — because the engine changed shape while it was being built. Read on before you pull if you have ever written a `ControlDeclaration::probed(...)` call, called `ProbeVerdict::reach(...)`, or typed anything against `Pulsar\Compliance\Probe\CapabilityProbe`.

Both signature changes fail loudly. PHP raises `ArgumentCountError` at the call site, so a mapping that has not been updated cannot be built at all — it will not quietly assess into a report that looks fine and is not.

### `ControlDeclaration::probed()` requires the estate the control regulates

`probed()` takes a sixth argument, `ControlSubject $subject`: the estate the standard's own text says the control is about.

Without it, a probe's facts were joined to a control by nothing, and the report had no way to notice that a measurement was about something other than what the control protects. The case that forced it: `audit_chain_verified` recomputes HMACs over the compliance evidence register — the compliance subsystem's log of itself — and was carrying twelve controls about the application's audit trail, the one written through `AuditSinkInterface`. See [ADR-0062](adr/0062-proof-must-be-about-the-control-subject.md).

Name the estate from the standard, not from the probe. A probe that named its own subject would agree with itself by construction, which is why the argument sits on the declaration.

```diff
                 probe: new TamperEvidentAuditProbe(),
+                subject: ControlSubject::AuditTrail,
```

Add the import alongside it:

```diff
+use Pulsar\Compliance\Control\ControlSubject;
```

`ControlSubject` enumerates twenty-one estates — `PersonalData`, `AuditTrail`, `ComplianceEvidenceRegister`, `SessionPayloads`, `KeyHierarchy`, `CryptographicPlatform` and the rest. The cases are deliberately narrow and deliberately flat: `HealthData` is not a part of `PersonalData`, so one operator scope assertion retires exactly the controls whose estate it names and no others. `DataInTransit` is the one case with parts, `DatabaseTransport` and `HttpTransport`.

### `ProbeVerdict::reach()` takes the subject to join against

`reach()` takes a third argument, the same `ControlSubject`. It performs the join the declaration now makes possible: a fact may prove a control only if the control's estate covers the fact's.

The engine passes it for you — `ControlFinding` reads it off the declaration — so this reaches you only if you call `reach()` directly, which in practice means a test harness or a custom report renderer.

```diff
-$verdict = ProbeVerdict::reach($probe->requirement(), $evidence);
+$verdict = ProbeVerdict::reach($probe->requirement(), $evidence, $declaration->assessedSubject());
```

`ControlDeclaration::assessedSubject(): ControlSubject` is new in rc.12 and is the supported way to read the estate back off a declaration.

### `ResilienceConfig::__construct()` takes the backup configuration

`ResilienceConfig` gains a fifth parameter, `BackupConfig $backup = new BackupConfig()`, and it sits **before** the trailing `$unknownKeys` rather than after it.

That placement is what breaks. A caller passing `$unknownKeys` positionally now passes it where the backup config is expected and fails on the type:

```diff
-new ResilienceConfig($enabled, $retry, $breaker, $health, $unknownKeys);
+new ResilienceConfig($enabled, $retry, $breaker, $health, unknownKeys: $unknownKeys);
```

Naming the argument is the whole migration; moving it one position right works too. `$unknownKeys` is last in every config DTO in this framework because it is not configuration -- it is the list of keys the DTO did not read, which [ADR-0036](adr/0036-unknown-config-key-detection.md) reports rather than ignores -- so a new setting is always inserted in front of it.

**Nothing that goes through `ResilienceConfig::fromArray()` is affected**, and that is how the config loader builds it. If you have never constructed a `ResilienceConfig` by hand, there is nothing to do.

The setting behind it is `config/resilience.php`'s new `backup` section, and it defaults to **enabled** -- the only switch in that file that does. Recovery is not a posture a deployment can decline: no deployment can assert that recovery does not apply to it, which is why NIST CSF RC.RP could never be scoped out. Being enabled binds the backup service and the plan so `pulsar backup:run` works and the compliance round trip has something real to exercise; it schedules nothing and takes no backup on its own. With no `PULSAR_MASTER_KEY` the wiring binds **nothing at all** rather than writing an unsealed archive, so `enabled` cannot produce a backup you would not want. See [docs/backup.md](backup.md) for what an archive contains, and for what it deliberately does not.

### `PseudonymizationProbe` and `BreachNotificationProbe` no longer extend `CapabilityProbe`

Both now implement `ControlProbeInterface` directly and build their own `ControlRequirement`.

`CapabilityProbe` grades every fact it is handed the same way, and these two probes have failure modes that must not be interchangeable. A pseudonymisation service that mints but cannot erase, and a mapping table that forgets on restart, are not degrees of one problem; neither are a register that will not accept a record and a register that empties on restart. Grading either pair Partial because the other held would let `composer compliance:check` pass over it, because the gate fails on Unsatisfied and not on Partial. Both probes therefore mark their deciding facts `RequiredFact::essential()`, which `CapabilityProbe` has no way to express.

`CapabilityProbe` itself is unchanged, still `#[Api]`, and still the base class for the other shipped probes and for yours. What breaks is code that treats those two as one:

```diff
-public function register(CapabilityProbe $probe): void
+public function register(ControlProbeInterface $probe): void
```

The same applies to a property or return type declared as `CapabilityProbe`, and to `$probe instanceof CapabilityProbe`, which is now false for both classes. `ControlProbeInterface` is the type they and `CapabilityProbe` all satisfy.

### The assessment now exercises subsystems instead of resolving them

Not an API change, but the thing most likely to surprise you the first time you run `composer compliance:check` on rc.12. A fact of the form "`SomeInterface` resolved to `SomeClass`" says which class would serve a request and never that the class did anything, so the controls that rested on those facts now rest on measurements that put a value through the live service:

- **Pseudonymisation.** A synthetic identifier is pseudonymised, resolved back byte for byte, then erased through the same service an Article 17 request would use. The erasure runs in a `finally`, so a failure earlier in the sequence still erases; if the erasure itself fails, the report names the identifier left behind rather than staying quiet about a row added to a re-identification table.
- **Session payloads.** A synthetic payload is sealed and opened again against the cipher this deployment actually bound, through the new `Pulsar\Security\Session\SessionPayloadCipherInterface`.
- **Incident register.** A synthetic incident is recorded, then found again by id. **This one cannot be taken back:** `IncidentReporterInterface` has no removal and should not grow one, so each report run leaves one row behind. It is written at `IncidentSeverity::Low`, below the threshold `BreachNotificationCheck` reads, so the probe row can never fail the check it exists to support; and it carries the source `compliance.incident_register_probe`, so every row this ever wrote can be found and filtered with one string. `compliance:report` is run by an operator or a pipeline rather than by a request, so the growth is one line per report, not one per page view.
- **AI transparency.** The transparency surface is driven rather than resolved.
- **Personal data at rest.** A field classified `ClassificationLevel::Pii` is put through the at-rest rule this framework applies to that classification, using the `EncryptorInterface` your deployment bound. The stored form must conceal the value, open to it byte for byte, refuse a copy with one byte changed, and differ between two seals of the same value. Nothing is written — the at-rest form is returned rather than stored. **If you bind your own `EncryptorInterface`**, this is the check that will tell you whether it is authenticated and randomised, and GDPR Art. 5(1)(f) and Art. 32 will fail if it is neither.

Read [ADR-0061](adr/0061-a-loaded-extension-is-not-a-measurement.md) through [ADR-0066](adr/0066-personal-data-is-measured-by-classifying-something.md) for why each of these had to become a measurement rather than a configuration read.

Expect your first rc.12 report to show fewer satisfied controls than rc.11 did. That is the change working: a control satisfied by a resolved binding was satisfied by a claim nobody had observed.

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

No namespaces were renamed in 1.0.0-rc.12. All classes remain under the `Pulsar\` root namespace. If you are upgrading from 0.2.x or earlier, the following namespaces were added in the 0.3.0-0.9.0 series:

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
composer require pulsar/framework:^1.0.0-rc.12
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
The following changes are behavioural rather than additive, and are recorded here
because they affect `#[Api]` types.

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

### `config/database.php` has no `pool` section, and `DatabaseConfig::$pool` is gone

The `pool` section was parsed into a `Pulsar\Database\Pool\PoolConfig` that nothing
read. No wiring built a `ConnectionPool` from it, on any runtime, so `min_connections`,
`max_connections`, `idle_timeout_seconds`, `max_lifetime_seconds` and
`health_check_interval_seconds` were five numbers an operator could size a database
around while they governed nothing at all.

Removed rather than left in place: a knob connected to nothing is worse than no knob,
because it is tuned in good faith. `ConnectionPool`, `PoolConfig`, `PooledConnection`
and `NullConnectionPool` all still ship and still work — an application that wants
pooling constructs one, which is now the only arrangement in which those numbers take
effect. See [database.md](database.md#connection-pooling).

**What to do:**

- Delete the `pool` block from `config/database.php`. Leaving it there is harmless but
  is now reported as an unknown config key.
- If you construct `DatabaseConfig` yourself, drop the `pool:` argument.
  `PoolConfig::fromArray()` is gone with the section that called it; construct
  `PoolConfig` directly. A positional call that passed five or more arguments now
  raises a `TypeError` at construction rather than binding the wrong parameter.
- `PoolConfig` no longer implements `ReportsUnknownKeys`, and its
  `unknownConfigKeys()` method and `$unknownKeys` constructor parameter are gone with
  it. Unknown-key reporting (ADR-0036) exists to tell an operator that a key in a
  config **file** was not read; with no `pool` section to parse there is no such key,
  and a DTO built in PHP reports its own typos as a `TypeError` already. Code that
  called `unknownConfigKeys()` on a `PoolConfig`, or that collected pool configs
  through the `ReportsUnknownKeys` interface, needs neither now.

### `read_write.write_host` selects the connection writes go to (was: read by nothing)

`read_hosts` and `write_host` are documented as hosts, and were passed straight to
`ConnectionManagerInterface::connection($name)`, which looks up connection **names**.
A deployment configured exactly as documented threw
`Database connection "replica-1.db.internal" is not configured` the first time a read
was routed. Nothing ever called the statement-aware routing method either, so with
`read_write.enabled` set, every read ran on the primary.

Both are fixed together, and the second half changes behaviour for anyone who had
`read_write.enabled` set: reads now actually reach the replicas.

**What to do:**

- If `write_host` is set in `config/database.php`, check it names the primary you
  intend. It now selects the default connection; it previously did nothing. An omitted
  `write_host` no longer defaults to `127.0.0.1` — it means "the default connection
  exactly as `connections` configures it".
- Read/write routing on SQLite, and routing with read hosts alongside multi-tenancy or
  failover, now fail at boot with a message naming the reason. Both configurations
  previously ran with no routing at all.

### Sequential migration versions are qualified by their source, not by the checkout path

Sequential migration filenames (`001_create_pages.php`) were versioned with a CRC32 of
the **absolute** migrations directory, so the same migration had a different version on
a developer machine and on a deploy host. A deploy into a different path made every
already-applied sequential migration read as pending, and `migrate:run` applied it a
second time to the production database.

Versions are now qualified by the name of the source that ships the migration, which is
identical on every host. `migrate:run` and `migrate:status` refuse to proceed when the
tracking table still holds versions of the old shape, and print the `UPDATE` statements
that re-key it.

**What to do:** if your database was written by rc.12 or earlier and any extension uses
sequential filenames, read
[Re-keying a table written before the change](migrations.md#re-keying-a-table-written-before-the-change)
before your next deploy. Projects using only timestamp filenames are unaffected.

### `HttpCacheMiddleware` refuses the shared store for anything carrying identity (was: stored it and served it to the next caller)

**This is a behaviour change. A deployment relying on the old caching will see cache misses
it did not see before.** The reason is the one that matters: the old middleware was serving
one authenticated caller's response to another caller.

The store is keyed by request method, path and query string and by nothing else, and it has
no per-user variant. Any entry produced for one caller was therefore an entry handed to
whoever asked for that key next — a signed-in dashboard, a statement, a page rendered with a
CSRF token or a `Set-Cookie` on it. The middleware now refuses in both directions instead of
keying by identity: a request carrying `Cookie`, `Authorization` or `Proxy-Authorization`
never reads or writes the store, and a response carrying `Set-Cookie`, `Vary`,
`Transfer-Encoding` or a `private` / `no-cache` / `no-store` directive is never stored. The
full refusal list is in [caching.md](caching.md#http-response-caching-httpcachemiddleware).

Refusing rather than keying by identity is deliberate. An identity-keyed shared cache
multiplies the blast radius of any future key defect by the number of users; a refusal costs
one cache miss.

Alongside it: `cache.private` no longer stores anything at all (the browser may still keep the
response; the shared store may not), and every decision is now labelled on the wire with
`X-Cache: HIT | MISS | BYPASS`.

**What to do:**

- Nothing, if you never piped `HttpCacheMiddleware` — it is opt-in and nothing wires it for you.
- If you did, expect the hit rate on authenticated traffic to go to zero, and read `X-Cache` to
  tell the two new outcomes apart: `MISS` is a cold cache that warms up, `BYPASS` is a request
  that will never be served from the store.
- If a route genuinely is the same for every caller and you want cookie-bearing browsers served
  from the cache anyway, set the `cache.share_across_clients` request attribute to `true` on it.
  It waives the three identity **request** headers and nothing else — the response-side refusals
  run afterwards and still win. It is an assertion the middleware cannot verify: those remaining
  checks read headers and do not inspect the body, so setting it on a route that renders anything
  caller-specific re-creates exactly the disclosure this change removed.
- Do not restore the old behaviour by widening the key with the session id. A shared store keyed
  on identity is the arrangement this change exists to remove.
  [ADR-0076](adr/0076-a-shared-cache-refuses-identity-rather-than-keying-on-it.md) records why,
  and is the record to argue with before reversing this.

### `NoCacheMiddleware` throws when it is wired where it can never see a route (was: added no header, silently)

`NoCacheMiddleware` enforces `#[NoCacheResponse]` on the dispatched route's handler. It used to
look the handler up in a request attribute that nothing in the framework has ever written, so it
added its headers to no response in any application while presenting itself — alias, attribute
and all — as an active control.

It now takes the route from the kernel, and throws a `LogicException` naming both supported
wirings when no pipeline gave it one. That happens exactly when it is piped into the **global**
pipeline, which runs before routing.

**What to do:** if you piped it globally, move it — either name the `no-cache` alias in the
`middleware:` list of the sensitive routes, or register it once in the `PostRoutingPipeline` to
cover the whole application. Both are shown in
[http.md](http.md#nocacheresponse-and-nocachemiddleware). A `LogicException` on the first request
after deploying is the intended outcome for the third, unsupported position: the alternative is
handing back a response that looks protected and is not.

### `StreamedResponse::getBody()` is non-destructive (was: consumed the response)

`getBody()` read the single-pass source and kept none of it, so one body-reading middleware
anywhere in the stack left the emitter with a response whose `getSource()` threw — headers
already sent, empty body. The source is now read once, kept, and answered from the buffer on
every later call including `getSource()`'s.

**What to do:** nothing. Code that only calls `getSource()` is unchanged. Code that calls
`getBody()` on a streamed response now buffers the payload in memory, which it always did — it
just no longer destroys the response as well.

### Out-of-pipeline error responses carry protective headers

An error raised during dispatch already travelled back out through `SecurityHeadersMiddleware`.
A boot failure or a throw from a global middleware could not, and shipped with whatever the
exception handler set — routinely no CSP, no framing policy and no referrer policy on the one
response most likely to be probed. The kernel now fills in the missing entries of
`ProductionRenderer::LAST_RESORT_HEADERS` on those two paths only, never overwriting a header the
response already carries and never touching `Content-Type`.

**What to do:** nothing, unless you assert on the exact header set of a boot-failure response in
your tests. The values are listed in [http.md](http.md#error-responses-carry-security-headers).

## Deprecation notices

No formal deprecations exist in 1.0.0-rc.12. The `#[Api]` / `#[Internal]` boundary replaces the informal "probably stable" / "probably internal" convention used in 0.x.

Classes that were commonly used in 0.x but are now marked `#[Internal]` should be treated as deprecated for external use. These include `Kernel`, `Version`, and `ConfigManager`. Use the public API surface documented in [public-api.md](public-api.md) instead.

## Getting help

- Run `php bin/pulsar diagnostics` to verify your environment.
- Run `php bin/pulsar list` to see all available commands.
- Review the [Public API Reference](public-api.md) for semver-stable types.
- Review the [Extensions Guide](extensions.md) for extension migration details.
