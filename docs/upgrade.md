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

### 5. Run diagnostics

```bash
php bin/pulsar diagnostics
```

Verify that all extensions load and all required PHP extensions are available.

### 6. Run tests

```bash
composer test
```

Fix any type errors or API changes surfaced by your test suite.

### 7. Run static analysis

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
one of those fallbacks meant *retain this data forever*:

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
throws `ConfigException` when a value is *present but unreadable*, when `category`
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
