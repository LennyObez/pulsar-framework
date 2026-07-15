# Framework caching & boot profiling

Pulsar's framework cache system pre-compiles configuration, routes, and container bindings into a single HMAC-signed payload. When a warm cache is present, the kernel boot path skips file-based parsing entirely, eliminating config I/O, route registration, and container resolution on every request.

## Cache pipeline

### Building the cache

```bash
# Standard cache build
php bin/pulsar optimize

# With encryption at rest (requires PULSAR_MASTER_KEY)
php bin/pulsar optimize --encrypt

# Strict mode - fail on closure-based routes
php bin/pulsar optimize --strict --encrypt
```

The `optimize` command builds four cache sections:

| Section          | Contents                                        |
| ---------------- | ----------------------------------------------- |
| `manifest`       | Schema version, app env, strict flag, timestamp |
| `config`         | Merged `ConfigRepository` snapshot              |
| `routes`         | Serialized `CachedRoute` DTOs                   |
| `containerHints` | Pre-resolved binding metadata                   |

All sections are bundled into a single file with an HMAC integrity envelope. The cache invalidation key is derived from a hash of config file contents and `composer.lock`, so caches self-invalidate when dependencies or configuration change.

### Aliases

`cache:warmup` is an alias for `optimize` - same behavior, discoverable under the `cache:` namespace:

```bash
php bin/pulsar cache:warmup --strict --encrypt
```

### Clearing the cache

```bash
php bin/pulsar optimize:clear
```

## Cache-aware boot

When a warm cache is present, the kernel boot pipeline changes:

| Phase              | Without Cache              | With Cache                  |
| ------------------ | -------------------------- | --------------------------- |
| Config loading     | Parse all PHP config files | Load from cached snapshot   |
| Route registration | Execute route files        | Hydrate `CachedRoute` DTOs  |
| Container setup    | Resolve bindings           | Apply pre-resolved hints    |
| Strict mode        | N/A                        | Lock router (no new routes) |

The boot pipeline detects cached data automatically - no code changes or flags are needed. When the manifest's `strict` flag is set, the router is locked after loading cached routes, preventing runtime route registration.

## CI validation

The `optimize:validate` command runs a full cache round-trip to verify that cache generation and loading work correctly. Intended for CI pipelines:

```bash
php bin/pulsar optimize:validate
php bin/pulsar optimize:validate --strict --encrypt
```

Steps performed:

1. **Warm** - runs `optimize` to build the cache
2. **Verify warm** - asserts `isWarm()` returns true
3. **Verify loadable** - loads the cache and checks all four sections are present
4. **Cleanup** - clears the validation cache

Exit code 0 on success, 1 on any failure. Example CI usage:

```yaml
- name: Validate framework cache
  run: php bin/pulsar optimize:validate --strict --encrypt
  env:
    PULSAR_MASTER_KEY: ${{ secrets.PULSAR_MASTER_KEY }}
```

## Boot profiler

The kernel records timing for each boot phase using `hrtime(true)` (nanosecond precision, monotonic clock). After boot, the profile is available as a `BootProfile` readonly DTO:

```php
$profile = $kernel->bootProfile();

$profile->totalUs;             // Total boot time in microseconds
$profile->cacheLoadUs;         // Cache load phase
$profile->configUs;            // Config loading phase
$profile->extensionRegisterUs; // Extension registration phase
$profile->extensionBootUs;     // Extension boot phase
$profile->cacheHit;            // Whether cache was loaded
$profile->routesCached;        // Whether routes came from cache
```

### Metrics emission

When a `MetricRegistry` is available in the container, the kernel emits a gauge after boot:

| Metric                    | Type  | Unit         | Description            |
| ------------------------- | ----- | ------------ | ---------------------- |
| `pulsar_boot_duration_us` | Gauge | Microseconds | Total kernel boot time |

## Route match timing

On the dispatch hot path, route matching is timed when a `MetricRegistry` is present. When metrics are disabled, the timing code is completely bypassed - zero overhead.

| Metric                  | Type      | Unit         | Buckets                         |
| ----------------------- | --------- | ------------ | ------------------------------- |
| `pulsar_route_match_us` | Histogram | Microseconds | 10, 25, 50, 100, 250, 500, 1000 |

The `MetricRegistry` reference is cached at boot time to avoid per-request container lookups.

## Deploy check severity overrides

Deploy checks support per-check severity configuration via `config/deploy.php`:

```php
'checks' => [
    'debug_mode'       => 'fail',   // Default severity
    'filesystem_scan'  => 'warn',   // Downgrade to warning
    'opcache_enabled'  => 'off',    // Skip entirely
],
```

Three severity levels:

| Level  | Behavior                                 |
| ------ | ---------------------------------------- |
| `fail` | Check runs normally (default)            |
| `warn` | Errors are downgraded to warnings        |
| `off`  | Check is replaced with a no-op skip stub |

### Environment variable overrides

Individual check severity can be overridden via environment variables, useful for CI or ephemeral environments:

```bash
DEPLOY_CHECK_OPCACHE_ENABLED_SEVERITY=off php bin/pulsar deploy:check
DEPLOY_CHECK_DEBUG_MODE_SEVERITY=warn php bin/pulsar deploy:check
```

The variable naming convention is `DEPLOY_CHECK_{NAME}_SEVERITY` where `{NAME}` is the check name in SCREAMING_SNAKE_CASE.

## Application cache serializers

The application cache layer (PSR-6/PSR-16 pools, [ADR-0018](adr/0018-application-cache-layer.md)) serializes every stored value. Each pool picks a serializer with `serializer:`:

- **`json`** (default) — encodes scalars and arrays only. It **rejects objects** with a `CacheException` rather than silently degrading. JSON has no code-execution surface on decode, so it is the safe default.
- **`php`** — uses PHP `serialize()`/`unserialize()` and can round-trip objects. Because unrestricted `unserialize()` is an object-injection vector, the `php` serializer is **fail-closed**: it only reconstructs classes listed in the pool's `allowed_classes`. An empty or absent allowlist permits **no** objects, and any class name in the allowlist that does not actually exist is dropped, so the allowlist can never widen deserialization to an unexpected type.

```php
// config/cache.php
'pools' => [
    'objects' => [
        'driver' => 'filesystem',
        'serializer' => 'php',
        'allowed_classes' => [\App\Dto\Money::class], // fail-closed allowlist
    ],
],
```

Only enable the `php` serializer for pools whose contents are entirely under your control, and keep `allowed_classes` as narrow as possible.

## See also

- [`deployment.md`](deployment.md) - Full deployment guide
- [`performance.md`](performance.md) - Benchmark harness
- [Performance budgets](performance.md): regression thresholds
- [`cli-reference.md`](cli-reference.md) - Full command reference
