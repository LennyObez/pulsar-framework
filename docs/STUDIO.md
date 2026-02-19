# Pulsar Studio

Pulsar Studio is a built-in observability and debugging subsystem for local development and staging environments. It captures structured events from the framework runtime, stores them in SQLite, and provides both a CLI and web interface for inspection.

## Overview

Studio collects events from HTTP requests, database queries, log writes, exceptions, scheduler jobs, and feature flag evaluations. Events are stored with an SHA-256 evidence chain for tamper detection.

Key features:

- Zero external dependencies (SQLite storage, PHP built-in server)
- Fiber-safe correlation context via `FiberScopedContextProvider`
- SHA-256 hash chain with optional BLAKE2b per-link MAC
- Environment-aware access control (local/staging/production)
- Real-time streaming via Server-Sent Events (SSE)
- Optional encryption-at-rest (XSalsa20-Poly1305)

## Configuration

Copy the config stub to enable Studio:

```php
// config/studio.php
return [
    'enabled' => true,
    'storage_path' => 'storage/studio/studio.sqlite',
    'sampling_rate' => 1.0, // 1.0 = 100%, 0.1 = 10%

    'retention' => [
        'max_age_days' => 7,
        'max_size_mb' => 500,
        'vacuum_interval_hours' => 24,
    ],

    'security' => [
        'allowed_cidrs' => ['127.0.0.1/8', '::1/128'],
        'production_confirm' => false,
    ],

    'server' => [
        'host' => '127.0.0.1',
        'port' => 8585,
    ],

    'collectors' => [
        'http' => true,
        'database' => true,
        'log' => true,
        'exception' => true,
        'scheduler' => true,
        'feature_flag' => true,
        'queue' => true,
    ],
];
```

### Environment Variable Overrides

| Variable                    | Config Key                    | Type   |
| --------------------------- | ----------------------------- | ------ |
| `STUDIO_ENABLED`            | `enabled`                     | bool   |
| `STUDIO_DISABLED`           | (disables Studio)             | bool   |
| `STUDIO_STORAGE_PATH`       | `storage_path`                | string |
| `STUDIO_SAMPLING_RATE`      | `sampling_rate`               | float  |
| `STUDIO_RETENTION_DAYS`     | `retention.max_age_days`      | int    |
| `STUDIO_PRODUCTION_CONFIRM` | `security.production_confirm` | bool   |

Setting `STUDIO_DISABLED=true` overrides all other settings and prevents Studio from loading.

## Kernel Integration

Studio uses a two-phase boot to ensure it decorates final service bindings:

```
Kernel::boot()
  ├── isStudioEnabled()          # Check config file + env vars
  ├── createLogger()             # Inject DeferredSink if Studio enabled
  ├── loadConfig()               # Load all configs including studio.php
  ├── registerExtensions()       # Extensions register services
  ├── bootExtensions()           # Extensions boot
  ├── studioPreboot()            # Phase 1: config + store + event factory
  └── attachStudioCollectors()   # Phase 2: decorate final bindings
```

Phase 1 (`preboot`) creates the `StudioManager`, event store, and redaction pipeline. Phase 2 (`attach`) wraps database connections, scheduler, and log sinks with instrumented decorators.

When Studio is disabled, neither phase runs and there is zero runtime overhead.

## Correlation Context

`FiberScopedContextProvider` provides fiber-safe correlation tracking. Each Fiber gets its own independent context stack via `WeakMap`, ensuring that concurrent Fibers never interfere with each other.

```php
$provider = new FiberScopedContextProvider();

// Enter a scope (typically in middleware)
$ctx = new CorrelationContext(requestId: 'req-abc', traceId: 'trace-123');
$scope = $provider->enter($ctx);

try {
    // All collectors read from $provider->current()
    // Database queries, log writes, exceptions — all carry req-abc
    handleRequest();
} finally {
    $scope->close(); // RAII guard — must close in finally
}
```

### Nested Scopes

Scopes nest naturally. A scheduler job triggered during an HTTP request creates a merged context:

```php
// HTTP middleware enters scope with requestId
$httpScope = $provider->enter(new CorrelationContext(requestId: 'req-abc'));

// Job handler enters nested scope with both requestId and jobId
$jobScope = $provider->enter(new CorrelationContext(
    requestId: 'req-abc',
    jobId: 'job-xyz',
));

// During job: current() returns context with both requestId + jobId
$provider->current(); // requestId=req-abc, jobId=job-xyz

$jobScope->close();

// After job: current() returns HTTP-only context
$provider->current(); // requestId=req-abc, jobId=null
```

## Event Pipeline

Every event follows this pipeline:

```
Collect → Resolve tenant → Redact → Serialize → Hash → Encrypt (optional) → Store + Chain
```

1. **Collect**: Collector captures raw data (e.g., `HttpCollector` captures request/response).
2. **Resolve tenant**: `StudioManager::ingest()` reads `TenantContext::tryGet()` and hashes the tenant ID.
3. **Redact**: `RedactionPipeline` scrubs secrets, tokens, DSNs, and passwords.
4. **Serialize**: Payload DTO converted to JSON via `toArray()` + `json_encode()`.
5. **Hash**: `payload_hash = hash('sha256', $redactedPlaintextJson)` computed before encryption.
6. **Encrypt** (optional): `EncryptedEventStore` encrypts the JSON payload using a dedicated subkey.
7. **Store + Chain**: Atomic SQLite transaction with `BEGIN IMMEDIATE` and retry on `SQLITE_BUSY`.

## Evidence Chain

The hash chain provides cryptographic integrity verification without external dependencies.

### Hash Chain (public, no keys required)

```
seed = hash('sha256', 'PULSAR_STUDIO_CHAIN_SEED')
link[0].hash = hash('sha256', seed . '|' . canonical(event[0]))
link[N].hash = hash('sha256', link[N-1].hash . '|' . canonical(event[N]))
```

Canonical form: `eventId|eventType|schemaVersion|timestampUs|traceId|payloadHash`

### Per-Link MAC (optional, requires `PULSAR_MASTER_KEY`)

Each link carries an optional BLAKE2b MAC computed from a dedicated subkey. This detects sophisticated attacks where an adversary modifies events and recomputes the SHA-256 chain.

### Verification Modes

| Mode           | Keys Required    | Detects                              |
| -------------- | ---------------- | ------------------------------------ |
| Public         | None             | Payload modification, chain breaks   |
| Tamper-evident | Chain MAC subkey | Recomputed chains, forged links      |
| Full           | All subkeys      | All of the above + ciphertext tamper |

### Window Verification

After retention pruning removes old events, verification operates in "window" mode using the earliest remaining link as the trust boundary.

## Collectors

| Collector                  | Events Captured               | Hook Point                         |
| -------------------------- | ----------------------------- | ---------------------------------- |
| `HttpCollector`            | `HttpRequest`, `HttpResponse` | Middleware (wraps pipeline)        |
| `InstrumentedConnection`   | `DatabaseQuery`               | Decorator on `ConnectionInterface` |
| `LogCollector`             | `LogEntry`                    | `LogSinkInterface` implementation  |
| `ExceptionCollector`       | `Exception`                   | Observer on `ErrorAggregator`      |
| `InstrumentedScheduler`    | `SchedulerRun`                | Decorator on `Scheduler`           |
| `FeatureFlagCollector`     | `FeatureFlagEval`             | Observer on `FlagEvaluationLog`    |
| `InstrumentedQueueManager` | `JobQueued`                   | Decorator on `QueueManager`        |
| `InstrumentedWorker`       | `JobCompleted`, `JobFailed`   | Decorator on `Worker`              |

All collectors receive a `CorrelationContextProviderInterface` to read the current context. Enable or disable individual collectors via `config/studio.php`.

### Queue Collector

The queue collector instruments both job dispatch and job execution:

- `InstrumentedQueueManager` wraps `QueueManager::dispatch()` and emits a `JobPayload` with status `queued` for each dispatched job, capturing the job class and target queue.
- `InstrumentedWorker` wraps `Worker::processNextJob()` and emits `JobPayload` events with status `completed` or `failed`. Each job gets a fiber-scoped correlation context with a unique `jobId`, and duration is measured via `hrtime()`.

## Security

### Access Control by Environment

| Environment | Requirements                                                                           |
| ----------- | -------------------------------------------------------------------------------------- |
| Local       | Always allowed                                                                         |
| Staging     | `STUDIO_ENABLED=true` + basic auth (if configured)                                     |
| Production  | `STUDIO_ENABLED=true` + `STUDIO_PRODUCTION_CONFIRM=true` + basic auth + CIDR allowlist |

### Production Safety

In production mode, `ProductionSafetyMode` restricts access to sensitive endpoints. Stack traces are never visible regardless of configuration.

## CLI Commands

### Umbrella Commands

| Command          | Description                           |
| ---------------- | ------------------------------------- |
| `studio:status`  | Show Studio status and storage stats  |
| `studio:serve`   | Start the Studio web server           |
| `studio:open`    | Open Studio in the default browser    |
| `studio:doctor`  | Run diagnostics (storage, port, keys) |
| `studio:enable`  | Enable Studio                         |
| `studio:disable` | Disable Studio                        |

### Console Commands

| Command                     | Description                           |
| --------------------------- | ------------------------------------- |
| `studio:console:status`     | Show event counts and retention stats |
| `studio:console:tail`       | Stream events in real-time            |
| `studio:console:query`      | Query events with filters             |
| `studio:console:export`     | Export evidence archive               |
| `studio:console:verify`     | Verify evidence chain integrity       |
| `studio:console:timeline`   | Display correlated event timeline     |
| `studio:console:metrics`    | Display collected metrics summary     |
| `studio:console:routes`     | Display route performance statistics  |
| `studio:console:exceptions` | Display exception groups and counts   |
| `studio:console:jobs`       | Display queue job statistics          |

### Evidence Commands

| Command                                   | Description                                 |
| ----------------------------------------- | ------------------------------------------- |
| `studio:console:evidence:export`          | Export Studio events as evidence archive    |
| `studio:console:evidence:verify`          | Verify a Studio evidence archive            |
| `studio:console:evidence:status`          | Display evidence store status               |
| `studio:console:evidence:retention:apply` | Apply retention policy to evidence store    |
| `studio:console:evidence:purge`           | Purge all events from evidence store        |
| `studio:console:evidence:redaction:test`  | Test redaction policies against sample data |

### Guardian Commands

| Command                                       | Description                                     |
| --------------------------------------------- | ----------------------------------------------- |
| `studio:console:guardian:status`              | Display combined guardian status overview       |
| `studio:console:guardian:check`               | Run all guardian checks (preflight + invariant) |
| `studio:console:guardian:deploy:check`        | Run deploy readiness checks                     |
| `studio:console:guardian:supervisor:status`   | Display supervisor configuration status         |
| `studio:console:guardian:supervisor:run-once` | Run a single supervisor evaluation cycle        |
| `studio:console:guardian:integrity:build`     | Build an integrity manifest                     |
| `studio:console:guardian:integrity:verify`    | Verify integrity manifest against filesystem    |

All commands support `--json` for machine-readable output.

### Examples

```bash
# Check Studio health
php bin/pulsar studio:doctor --json

# Stream HTTP events in real-time
php bin/pulsar studio:console:tail --type=http.request

# Query events by correlation ID
php bin/pulsar studio:console:query --request-id=abc123 --json

# View correlated event timeline for a request
php bin/pulsar studio:console:timeline --request-id=abc123

# View queue job statistics
php bin/pulsar studio:console:jobs --json

# Export and verify evidence chain
php bin/pulsar studio:console:evidence:export --output=evidence.studio
php bin/pulsar studio:console:evidence:verify evidence.studio --mode=tamper-evident --json

# Run guardian checks
php bin/pulsar studio:console:guardian:check --json

# Verify file integrity via guardian
php bin/pulsar studio:console:guardian:integrity:verify --strict --json
```

## Web Interface

Start the Studio server:

```bash
php bin/pulsar studio:serve
# → http://127.0.0.1:8585/studio
```

The web UI provides:

- **Landing page**: Overview of all subsystems
- **Console overview**: Real-time event stream with SSE
- **Request explorer**: HTTP request/response details
- **Database explorer**: Query performance and slow queries
- **Log explorer**: Structured log entries
- **Exception explorer**: Error grouping and fingerprinting
- **Timeline view**: Correlated event reconstruction per request or job

### Live Mode

The console overview supports real-time streaming via Server-Sent Events. Toggle between "Live" and "Paused" modes in the UI. Events appear within one second of capture.

## Key Separation

Studio uses three distinct derived subkeys from `PULSAR_MASTER_KEY`:

| Purpose        | Subkey ID | Context String |
| -------------- | --------- | -------------- |
| Encryption     | 3         | `stud_enc`     |
| Archive MAC    | 4         | `stud_mac`     |
| Chain link MAC | 5         | `stud_chn`     |

When `PULSAR_MASTER_KEY` is not set, encryption and MAC features degrade gracefully. The SHA-256 chain remains fully functional and publicly verifiable.

## SQLite Storage

Studio uses SQLite in WAL (Write-Ahead Logging) mode for concurrent read/write access. Write contention is handled with:

- `BEGIN IMMEDIATE` transactions for chain linearization
- `busy_timeout = 5000` PRAGMA for reader/writer coordination
- Application-level retry with exponential backoff (5 attempts, base 5ms, 3x multiplier)
- `studio.store.busy` metric counter for monitoring contention

## Zero-Overhead When Disabled

When Studio is not enabled:

- No `DeferredSink` is created in the logger
- No decorator wrappers are applied to services
- No observer callbacks are registered
- No SQLite file is created
- The only check is `isStudioEnabled()` which reads a config file path once during boot
