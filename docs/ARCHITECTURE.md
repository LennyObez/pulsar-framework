# Pulsar Architecture

This document describes the high-level architecture of the Pulsar Framework.

## Design Principles

### 1. Small, Fast Core

The framework core is intentionally minimal. It provides:

- Kernel lifecycle management
- Service container (PSR-11 compatible)
- HTTP abstraction
- Router
- Basic observability hooks

Everything else is an extension.

### 2. Explicit Over Magic

- No auto-discovery of services unless explicitly configured
- Constructor injection preferred over service locators
- Type-safe configuration
- Predictable boot sequence

### 3. Extension-First

The core and first-party features use the same extension API that third parties use.
There is no "privileged" access for built-in functionality.

### 4. Compile-Time Over Runtime

Where possible, work is shifted from runtime to compile/build time:

- Container bindings can be compiled
- Routes can be cached
- Configuration can be validated at build time

## Core Components

### Kernel (`src/Core/`)

The kernel manages the application lifecycle:

```
Boot → Configure → Handle Request → Send Response → Shutdown
```

Key responsibilities:

- Load configuration
- Initialize service container
- Register extensions
- Delegate request handling

### Container (`src/Container/`)

PSR-11 compatible dependency injection container.

Features:

- Interface-to-implementation binding
- Factory functions
- Singleton and transient lifetimes
- Compiled container support (future)

### HTTP (`src/Http/`)

HTTP abstraction layer.

Components:

- Request representation
- Response representation
- Middleware pipeline

### Router (`src/Routing/`)

URL routing with support for:

- Static routes
- Parameterized routes
- Route groups
- Middleware assignment

### Console (`src/Console/`)

CLI application framework.

Features:

- Command discovery
- Argument parsing
- Output formatting

### Extensibility (`src/Extensibility/`)

Extension system for adding functionality.

Extension lifecycle:

1. Discover (find `pulsar.json` manifests)
2. Validate (check compatibility)
3. Register (bind services, routes, commands)
4. Boot (initialize extension)

### Configuration (`src/Config/`)

Typed configuration system with deterministic load order.

Pipeline:

1. OS environment variables (highest priority)
2. `.env` file (optional, never overrides OS vars)
3. PHP config files (`config/app.php`, `config/observability.php`)
4. Runtime overrides (`ConfigOverrides`, merged via `array_replace_recursive`)
5. Typed DTO construction (`AppConfig::fromArray()`, `ObservabilityConfig::fromArray()`)

Components:

- `Environment` — loads and merges env vars from OS + `.env` file
- `EnvironmentMode` — backed enum (`Local`, `Staging`, `Production`) with debug defaults
- `AppConfig` / `ObservabilityConfig` — readonly DTOs with `fromArray()` factories
- `ConfigRepository` — typed store keyed by class name
- `ConfigManager` — orchestrator that runs the full pipeline
- `ConfigLoaderInterface` — extension point for custom config DTOs (not consumed by core in 0.3.0)

### Error Handling (`src/ErrorHandling/`)

Centralized exception handling with environment-aware rendering.

Flow: Exception → resolve HTTP status → resolve headers → log with context → render response body.

Components:

- `ExceptionHandler` — central handler resolving status, headers, logging, and rendering
- `HttpException` / `HttpExceptionInterface` — exceptions that map to specific HTTP status codes
- `DevelopmentRenderer` — detailed HTML with trace, request details, previous exceptions (debug=true)
- `ProductionRenderer` — safe generic HTML, no sensitive info exposed (debug=false)

Status resolution:

- `HttpExceptionInterface` → its `getStatusCode()`
- `RoutingException` 404 → `NotFound`, 405 → `MethodNotAllowed` with `Allow` header
- Default → `InternalServerError` (500)

### Security (`src/Security/`)

Security primitives:

- Session management
- CSRF protection
- Rate limiting
- Encryption utilities

### Observability (`src/Observability/`)

In-house observability suite:

- Structured logging (PSR-3 compatible)
- Metrics collection
- Distributed tracing
- Audit logging

#### Logging (`src/Observability/Log/`)

PSR-3 compliant structured logging with JSON lines output.

Components:

- `Logger` — implements `Psr\Log\LoggerInterface` with level threshold filtering
- `LogLevel` — backed string enum with numeric severity (0=emergency..7=debug) and `meetsThreshold()`
- `LogEntry` — readonly value object with UTC timestamp, level, message, context, channel
- `LogFormatter` — JSON line formatter with PSR-3 `{placeholder}` interpolation and `Throwable` serialization
- `LogSinkInterface` — output destination contract
- `FileSink` — appends JSON lines to file, creates directories, uses `LOCK_EX`
- `StreamSink` — writes JSON lines to PHP streams (`php://stderr`, etc.)

Design decisions:

- Sink write failures are silently swallowed (logging never crashes a request)
- `Logger::fromConfig()` factory builds a configured logger from `ObservabilityConfig`
- Registered in container as both `Psr\Log\LoggerInterface` and `Pulsar\Observability\Log\Logger`

## Module System (HMVC)

Pulsar uses Hierarchical Model-View-Controller for large applications.

A module is:

- Self-contained (own controllers, views, models)
- Explicitly bounded (declared dependencies)
- Versioned (can evolve independently)

Module structure:

```
modules/<module-name>/
├─ pulsar.json      # Module manifest
├─ src/
│  ├─ Controllers/
│  ├─ Models/
│  └─ Services/
├─ views/
├─ routes/
└─ tests/
```

## Request Lifecycle

1. Web server receives request
2. `public/index.php` creates kernel (optionally with `ConfigManager`)
3. Kernel boots:
   a. If `ConfigManager` provided: load config → register config services → create logger → create exception handler
   b. Register extensions
   c. Boot extensions
4. Request is parsed and wrapped
5. Router matches route
6. Middleware pipeline executes
7. Controller handles request
8. Response is returned through middleware
9. If an exception occurs and `ExceptionHandler` is present: log exception, render error response
10. Response is sent
11. Kernel shuts down

## Directory Layout

See `docs/REPOSITORY_STRUCTURE.md` for the complete directory structure.

## API Stability

Pulsar uses a PHP attribute-based system to explicitly mark API surface boundaries.

### Stability Attributes

- `#[Api]` (`src/Api/Api.php`) -- marks a class, method, or interface as part of the **public API**. These symbols are covered by semantic versioning guarantees: breaking changes require a major version bump.
- `#[Internal]` (`src/Api/Internal.php`) -- marks a symbol as **framework-internal**. Internal symbols may change or be removed in any release without notice. Extension authors and application code must not depend on internal symbols.

### Semver Guarantees

Only symbols annotated with `#[Api]` are covered by semver. Specifically:

- **Patch releases** (1.0.x) -- bug fixes only, no API changes.
- **Minor releases** (1.x.0) -- new `#[Api]` symbols may be added; existing ones are never removed or changed incompatibly.
- **Major releases** (x.0.0) -- `#[Api]` symbols may be removed or changed.

Symbols without either attribute are treated as internal by default.

For the complete public API reference, see [`docs/PUBLIC_API.md`](PUBLIC_API.md).

## Performance Budgets

Pulsar includes a PHPBench-based benchmark suite (`tests/Benchmark/`) with CI-enforced performance budgets.

- **Benchmark suite**: PHPBench benchmarks cover critical hot paths (bootstrap, routing, container resolution, middleware pipeline, response emission).
- **Budget definitions**: Budgets are declared in `tools/php/performance-budgets.json` and enforced during CI runs.
- **PHPBench configuration**: See `tools/php/phpbench.json` for runner configuration.
- **CI enforcement**: Performance regressions that exceed the defined budgets will fail the CI pipeline, preventing accidental degradation of framework performance.

### Studio (`src/Studio/`)

Built-in observability and debugging subsystem. See [`docs/STUDIO.md`](STUDIO.md) for full documentation.

Architecture:

- **Two-phase Kernel boot**: `preboot()` (config + store + event factory) then `attach()` (decorate final service bindings after extensions boot)
- **Collector pattern**: Middleware, decorators, sinks, and observers capture events without modifying core component interfaces
- **Event pipeline**: Collect → Redact → Serialize → Hash → Encrypt (optional) → Store + Chain
- **Evidence chain**: SHA-256 hash chain with optional BLAKE2b per-link MAC for tamper detection

Components:

- `StudioManager` — central orchestrator for event ingestion with sampling
- `FiberScopedContextProvider` — fiber-safe correlation context via `WeakMap` per Fiber
- `SqliteEventStore` — SQLite storage with WAL mode and write contention retry
- `HashChain` / `EvidenceVerifier` — cryptographic integrity verification
- `StudioServer` / `StudioRouter` — PHP built-in server with SSE support
- `StudioAccessGate` — environment-aware access control (local/staging/production)

#### Concurrency Model

`FiberScopedContextProvider` uses a `WeakMap<object, SplStack<CorrelationContext>>` keyed by Fiber identity. Each Fiber gets its own independent scope stack:

- Main thread uses a stable `stdClass` root key
- Each Fiber uses `Fiber::getCurrent()` as its key
- When a Fiber is garbage-collected, its `WeakMap` entry is automatically reclaimed
- `ContextScope` is an RAII guard that must be closed from the same Fiber that called `enter()`

This design ensures sequential runtime behavior (all scopes on the root key) while being safe under Fiber concurrency without code changes.

#### SQLite Write Contention

Studio uses SQLite in WAL mode with `BEGIN IMMEDIATE` transactions for chain linearization:

- `busy_timeout = 5000` PRAGMA provides reader/writer coordination at the SQLite level
- Application-level retry on `SQLITE_BUSY` / `SQLITE_LOCKED`: 5 attempts with exponential backoff (base 5ms, 3x multiplier, ±50% jitter)
- Each retry re-runs the entire transaction closure (fresh `BEGIN IMMEDIATE` + fresh chain tip read)
- `studio.store.busy` metric counter tracks contention events

This guarantees a linear evidence chain even under concurrent multi-process writes (PHP-FPM workers, RoadRunner workers).

#### Evidence Chain After Retention

Retention enforcement (`RetentionEnforcer`) deletes old events and their chain links. After pruning, `EvidenceVerifier` operates in "window" mode:

- The earliest remaining link's `previous_hash` becomes the trust boundary (not verified)
- All remaining links are verified from that anchor to the latest link
- `links_pruned` counter in `studio_meta` tracks how many links were removed
- Verification reports include mode (`full` / `window`), anchor type, and pruned count

### Concurrency Model

Pulsar is synchronous by design. The framework processes one request at a time per worker, with no event loop, hidden scheduler, or implicit parallelism. Fibers are used in exactly one place -- Studio's `FiberScopedContextProvider` -- for correlation context isolation across Fiber boundaries via a `WeakMap` keyed by Fiber identity. When no Fiber is active, all context operations fall back to a root key with zero overhead.

For full details on Fiber usage, extension constraints, and framework guarantees, see [`docs/ASYNC_MODEL.md`](ASYNC_MODEL.md).

## Operational Layer

The operational layer provides production-grade subsystems for job processing, process supervision, file integrity verification, and deploy readiness checks. These subsystems are wired into the Kernel boot pipeline and integrate with Studio for observability.

### Queue (`src/Queue/`)

Asynchronous job processing with pluggable drivers.

Components:

- `QueueManager` — central orchestrator for job dispatch and queue state queries
- `Worker` — long-running process that polls a queue, executes jobs, and handles graceful shutdown
- `WorkerOptions` — configurable limits (max jobs, memory, timeout, sleep interval)
- `QueueDriverInterface` — pluggable backend contract
- `SyncDriver` — executes jobs immediately in the same process (local/testing)
- `InMemoryDriver` — in-memory FIFO queue (testing)
- `DatabaseDriver` — persistent queue backed by a database table (production)
- `QueueRetryPolicy` — configurable retry with exponential backoff and max attempts
- `DeadLetterQueue` — stores permanently failed jobs for inspection and manual retry

Worker lifecycle:

```
Start → Register signal handlers → Poll loop
  ├── Pop next job
  ├── Execute (instantiate class, call handle())
  ├── Acknowledge or reject
  ├── Check recycle conditions (max jobs, memory, uptime)
  └── Sleep if idle
→ Graceful shutdown (SIGINT/SIGTERM or recycle)
```

Configuration via `config/queue.php`. See [`docs/CLI_REFERENCE.md`](CLI_REFERENCE.md) for queue commands.

### Supervisor (`src/Supervisor/`)

Process health monitoring and automated recovery.

Components:

- `Supervisor` — central orchestrator for worker lifecycle evaluation
- `WorkerRecyclePolicy` — threshold-based recycling (request count, memory, uptime)
- `StuckJobDetector` / `StuckJobPolicy` — detects jobs that exceed expected execution time
- `PreflightRunner` — pre-start health checks (e.g., database connectivity, disk space)
- `InvariantRunner` — runtime invariant checks during worker execution

Key operations:

- `shouldRecycle()` — evaluates if a worker should be recycled based on configured thresholds; returns a `RecycleRecord` with the reason and recommended action
- `detectStuckJobs()` — queries the queue driver for jobs exceeding the stuck timeout
- `recoverStuckJobs()` — dead-letters stuck jobs and returns healing actions
- `runPreflightChecks()` / `runInvariantChecks()` — executes registered check lists

Configuration via `config/supervisor.php`.

### File Integrity (`src/Integrity/`)

Filesystem integrity verification using cryptographic manifests.

Components:

- `ManifestBuilder` — scans configured paths, computes SHA-256 hashes, produces an `IntegrityManifest`
- `ManifestVerifier` — compares a stored manifest against the current filesystem; reports modified, missing, and added files
- `ManifestSigner` — HMAC-BLAKE2b signing and verification using a derived subkey (subKeyId=6, context=`integ_sg`)
- `IntegrityPolicy` — resolved policy from configuration (enforcement mode)

Verification flow:

```
Build manifest → Sign (optional, requires PULSAR_MASTER_KEY)
                → Store on disk
                → Later: verify against current filesystem
                  ├── Modified files (hash mismatch)
                  ├── Missing files (in manifest, not on disk)
                  └── Added files (on disk, not in manifest)
```

Configuration via `config/integrity.php`. See [`docs/INTEGRITY.md`](INTEGRITY.md) for detailed usage.

### Deploy Checks (`src/Deploy/`)

Pre-deployment readiness validation.

Components:

- `DeployCheck` — orchestrator that runs all registered checks against a target environment
- `DeployCheckInterface` — contract for individual checks (`getName()`, `getDescription()`, `check()`)
- `CheckResult` — result DTO with severity level and actionable recommendations
- `DeployReport` — aggregated report with pass/warning/error counts

Deploy checks validate environment-specific requirements before deployment (e.g., config completeness, cache state, security settings). Checks can target specific environments (`local`, `staging`, `production`).

Configuration via `config/deploy.php`. See [`docs/DEPLOYMENT.md`](DEPLOYMENT.md) for detailed usage.

### Framework Caching (`src/Cache/`)

Build-time cache system for configuration, routes, and container bindings.

Components:

- `FrameworkCache` — top-level orchestrator for warm/clear/load operations
- `ConfigCache` — serializes `ConfigRepository` with optional encryption
- `RouteCache` — serializes compiled route tables
- `ContainerCache` — serializes container bindings
- `CacheIntegrity` — HMAC signing and atomic file writes
- `CacheManifest` — HMAC-signed manifest with deterministic invalidation keys
- `CacheLock` — `flock()`-based write lock for concurrent safety

Cache-aware boot path:

```
Kernel::boot()
  ├── Check FrameworkCache in container
  ├── If cached: load ConfigRepository from cache (skip file parsing)
  └── If not cached: normal config load from PHP files
```

The cache invalidation key is computed from a hash of config file contents and `composer.lock`, ensuring automatic invalidation when dependencies or configuration change.

## Extension Points

Extensions can hook into:

- Service container (register bindings)
- Router (add routes)
- Console (add commands)
- Middleware (add global middleware)
- Event dispatcher (listen to events)
- Configuration (provide defaults)
