# Multi-Runtime Support

Pulsar supports four HTTP runtimes with a unified interface. Each runtime implements the same kernel lifecycle but differs in how requests arrive and how process management works. The framework auto-detects the best available runtime or accepts explicit configuration.

## Available Runtimes

### PHP-FPM

The default fallback runtime. Wraps the standard `Kernel::run()` lifecycle with zero overhead. Each request runs in its own PHP-FPM worker process, providing natural process-level isolation.

- Process-per-request model (no sandbox needed)
- No additional PHP extensions required
- Best for: shared hosting, traditional deployments, simplest operational model

### Persistent (Built-in)

A long-running HTTP/1.1 origin server using `ext-sockets`. The kernel boots once and handles many requests with strict per-request isolation via `RequestSandbox`.

- Fiber-based cooperative concurrency (optional)
- Keep-alive connections with configurable idle timeout
- Full HTTP/1.1 origin server with chunked transfer decoding
- WebSocket upgrade support via `UpgradeResponse`
- Slowloris and body timeout protections
- Best for: development, testing, simple production deployments behind a reverse proxy

### FrankenPHP

Worker mode via FrankenPHP (a PHP SAPI built on Caddy). Uses `frankenphp_handle_request()` to process requests in a persistent worker with boot-once semantics.

- Native HTTP/3 and 103 Early Hints support (via Caddy)
- Automatic TLS with Let's Encrypt
- `earlyHints()` API for preloading critical resources
- Best for: production with HTTP/3, integrated TLS termination, Caddy-native deployments

### RoadRunner

PSR-7 worker protocol via the RoadRunner application server. Receives PSR-7 `ServerRequestInterface` objects, converts them through a `PsrBridge`, processes through the kernel, and returns PSR-7 responses.

- Requires the RoadRunner binary and `spiral/roadrunner-http` SDK
- Configurable worker pools managed by RoadRunner
- Native gRPC, queue, and KV support alongside HTTP
- Best for: production with advanced worker pool management, multi-protocol setups

### Comparison

| Aspect            | PHP-FPM       | Persistent                 | FrankenPHP                 | RoadRunner                 |
| ----------------- | ------------- | -------------------------- | -------------------------- | -------------------------- |
| Boot              | Every request | Once per worker            | Once per worker            | Once per worker            |
| State isolation   | Process-level | RequestSandbox             | RequestSandbox             | RequestSandbox             |
| Concurrency       | php-fpm pool  | Fiber scheduler            | Caddy worker pool          | RoadRunner pool            |
| TLS               | Web server    | Reverse proxy              | Native (Caddy)             | Reverse proxy              |
| HTTP/3            | Via proxy     | No                         | Native                     | Via proxy                  |
| Memory management | Process exit  | Leak detection + recycling | Leak detection + recycling | Leak detection + recycling |
| Requirements      | None          | ext-sockets                | FrankenPHP binary          | RoadRunner binary          |

## Runtime Selection

### Auto-Detection

When `driver` is set to `auto` (the default), Pulsar probes the environment in this priority order:

1. **FrankenPHP** -- `frankenphp_handle_request()` function exists
2. **RoadRunner** -- `RR_MODE` environment variable is set
3. **Persistent** -- `ext-sockets` extension is loaded
4. **FPM** -- always available (fallback)

The `RuntimeResolver` class performs detection and returns the resolved `RuntimeType` enum.

### Configuration

Set the driver in `config/runtime.php`:

```php
'driver' => 'auto', // or: 'fpm', 'persistent', 'frankenphp', 'roadrunner'
```

Or override via environment variable:

```bash
RUNTIME_DRIVER=frankenphp
```

### CLI Override

The `runtime:serve` command accepts a `--runtime` flag:

```bash
php bin/pulsar runtime:serve --runtime=persistent
php bin/pulsar runtime:serve --runtime=frankenphp
```

## Request Isolation

Persistent runtimes (Persistent, FrankenPHP, RoadRunner) share a single kernel instance across requests. The `RequestSandbox` enforces strict per-request isolation through a deterministic cleanup sequence.

### Sandbox Lifecycle

On every request:

1. **Before**: Apply hygiene profile (reset superglobals, clear error state) and snapshot memory baseline
2. **Before**: Begin container request scope (scoped instances created fresh)
3. **Handle**: Kernel processes the request normally
4. **After**: End container request scope (evicts scoped instances via `ScopeManager`)
5. **After**: Evict legacy request-bound services (`forgetInstance`)
6. **After**: Reset resettable singletons (`ResettableInterface::resetRequestState()`)
7. **After**: Check leak detector for memory growth warnings

### Hygiene Layer

The `PersistentRuntimeHygiene` profile runs before each request:

- **SuperglobalResetter** -- clears `$_GET`, `$_POST`, `$_COOKIE`, `$_SERVER`, `$_FILES`, `$_REQUEST`, `$_SESSION`
- **ErrorStateResetter** -- clears `error_get_last()` state and restores error reporting

### Service Lifetimes

| Lifetime       | Behavior                        | Examples                               |
| -------------- | ------------------------------- | -------------------------------------- |
| Singleton      | Persists across requests (safe) | Container, Router, Config DTOs, Logger |
| Request-scoped | Evicted after each request      | `SecurityContext` (identity, session)  |
| Resettable     | Reset via `resetRequestState()` | `TenantContext`, `FlagEvaluationLog`   |

**Hard invariant**: Two sequential requests MUST never share identity, session, or tenant state.

### Implementing ResettableInterface

Services that hold mutable request-scoped state should implement `ResettableInterface`:

```php
use Pulsar\Runtime\ResettableInterface;

final class MyService implements ResettableInterface
{
    private array $cache = [];

    public function resetRequestState(): void
    {
        $this->cache = [];
    }
}
```

Register the service in the `RequestResetRegistry` during kernel boot.

### State Safety

The `StatefulSingletonAnalyzer` detects singletons with mutable state at build time:

- Writable (non-readonly) instance properties on singleton services
- Mutable static properties
- Classes with `reset()` or `resetRequestState()` methods (indicates manual state management)

In strict mode, violations in core namespaces throw `StatefulSingletonException`. In lenient mode, violations are logged as warnings.

## Worker Lifecycle

All persistent runtimes follow the same lifecycle states:

```
Booting -> Ready -> Handling -> Draining -> Recycling -> Stopped
```

| State       | Description                                    |
| ----------- | ---------------------------------------------- |
| `Booting`   | Kernel booting, signal handlers registered     |
| `Ready`     | Accepting connections                          |
| `Handling`  | Processing a request                           |
| `Draining`  | Reload requested, finishing in-flight requests |
| `Recycling` | Thresholds exceeded, finishing and exiting     |
| `Stopped`   | Worker exited, supervisor restarts it          |

Workers recycle automatically when any threshold is exceeded:

- **Max requests** (`max_requests`, default: 10,000) - prevents unbounded per-request allocation growth
- **Memory threshold** (`memory_threshold_mb`, default: 256 MB) - hard limit on RSS
- **Time limit** (`time_limit_seconds`, default: 7,200s) - guards against long-lived state drift

### Fatal Error Containment

A `register_shutdown_function` handler catches fatal errors (`E_ERROR`, `E_CORE_ERROR`, `E_COMPILE_ERROR`), emits a recycle event, and exits non-zero so the process supervisor restarts the worker. No corrupt state persists.

## Health Endpoint

When `health_endpoint` is enabled (default: `true`), all persistent runtimes serve a `/_health` endpoint that bypasses the kernel entirely.

### Request

```
GET /_health HTTP/1.1
```

### Response

```json
{
  "status": "healthy",
  "requests": 4521,
  "memory_mb": 48,
  "uptime_s": 3600
}
```

| Field       | Type   | Description                               |
| ----------- | ------ | ----------------------------------------- |
| `status`    | string | `healthy`, `draining`, or `shutting_down` |
| `requests`  | int    | Total requests handled by this worker     |
| `memory_mb` | int    | Current memory usage in MB                |
| `uptime_s`  | int    | Seconds since worker start                |

### HTTP Status Codes

| Worker State              | Status Code               |
| ------------------------- | ------------------------- |
| Healthy                   | `200 OK`                  |
| Draining or shutting down | `503 Service Unavailable` |

Use this endpoint for load balancer health probes. A `503` signals the load balancer to stop sending traffic while the worker drains and recycles.

## Graceful Reload

All persistent runtimes implement `ReloadableRuntimeInterface` with a graceful reload lifecycle:

1. **Signal received** -- `SIGUSR1`, HTTP endpoint, or CLI command
2. **Enter draining state** -- stop accepting new requests
3. **Drain in-flight requests** -- wait up to `drain_timeout_seconds` (default: 30s)
4. **Recycle** -- worker exits, process supervisor spawns a fresh process

```bash
# Send reload signal to a running worker
kill -USR1 <worker-pid>

# Or via CLI
php bin/pulsar runtime:reload
```

## Memory Stability

### Leak Detector

The `LeakDetector` runs on every request in persistent runtimes:

1. Snapshots memory before request handling
2. Tracks explicitly registered resources (file handles, connections)
3. After the request, checks for unreleased resources and memory growth
4. Emits warnings via the logger when thresholds are exceeded

In strict mode, unreleased resources throw `ResourceLeakException`.

### Leak Sentinel (CI Gate)

The `LeakSentinel` is a CI-time check that validates memory stability across many requests:

1. Boot the application once
2. Send 10,000 deterministic fixture requests
3. Take memory snapshots at configurable points (default: 100, 1000, 5000, 10000)
4. Assert that memory growth stays under thresholds (default: 5% or 2 MB)

Configuration via `LeakSentinelConfig`:

| Parameter                  | Default                  | Description                  |
| -------------------------- | ------------------------ | ---------------------------- |
| `total_requests`           | 10,000                   | Requests to simulate         |
| `snapshot_points`          | [100, 1000, 5000, 10000] | When to measure memory       |
| `growth_percent_threshold` | 5.0%                     | Max memory growth percentage |
| `growth_bytes_threshold`   | 2 MB                     | Max memory growth in bytes   |

## Persistent Runtime Details

### Protocol Support

The built-in persistent runtime provides a full HTTP/1.1 origin server:

- Keep-alive connections (configurable)
- Request pipelining (sequential, in-order)
- Chunked request decoding with size limits
- `Content-Length` always present in responses

### Chunked Requests

- `Transfer-Encoding: chunked` is fully supported
- Decoded body buffered up to `max_body_size`
- Chunk extensions longer than 256 bytes are rejected
- Both `Transfer-Encoding` and `Content-Length` present results in 400
- Non-`chunked` Transfer-Encoding results in 501

### Error Responses

| Condition                     | Status | Behavior |
| ----------------------------- | ------ | -------- |
| Malformed request line        | 400    | Close    |
| Invalid header (no colon)     | 400    | Close    |
| Chunked + Content-Length      | 400    | Close    |
| Headers too large             | 431    | Close    |
| Body too large                | 413    | Close    |
| Unsupported Transfer-Encoding | 501    | Close    |
| Handler exception             | 500    | Close    |
| Header timeout (slowloris)    | 408    | Close    |

### WebSocket / Upgrade

- Route handler returns `UpgradeResponse` with an `UpgradeHandlerInterface`
- Runtime sends 101 Switching Protocols, then transfers socket ownership
- Upgrade handlers receive `UpgradeContext` (Logger, Metrics, Config only - no container)
- Request sandbox cleanup runs before connection handoff

### Fiber Concurrency

When `fiber_concurrency > 0`, the runtime uses a cooperative Fiber scheduler:

- One Fiber per accepted connection
- Main loop uses `socket_select()` to find readable sockets
- Fibers suspend when I/O would block
- Backpressure: stops accepting when at concurrency limit

Fibers provide I/O concurrency only - PHP remains single-threaded. Blocking DB calls without async drivers do not benefit from Fibers. The value is in concurrent socket I/O (accept + read + write overlap). Set `fiber_concurrency = 0` (default) for a synchronous accept loop, which is simpler and sufficient for most workloads behind a load balancer.

### The `--public` Flag

By default, the persistent runtime only binds to loopback addresses (`127.0.0.1`, `::1`, `localhost`). To bind to any other address (e.g., `0.0.0.0`), pass `--public` to acknowledge that:

- This server does not terminate TLS
- It should sit behind a reverse proxy in production
- The address may be accessible on the network

## CLI Commands

### `runtime:serve`

Start the HTTP runtime server.

```bash
php bin/pulsar runtime:serve [options]

Options:
  --host           Address to bind (default: from config)
  --port           Port to listen on (default: from config)
  --max-requests   Max requests before recycling (default: from config)
  --memory         Memory threshold in MB (default: from config)
  --timeout        Time limit in seconds (default: from config)
  --concurrency    Fiber concurrency, 0=sync (default: from config)
  --runtime        Runtime driver override (fpm, persistent, frankenphp, roadrunner)
  --public         Required to bind to non-loopback addresses
```

### `runtime:status`

Display the current runtime status, including worker state, request count, memory usage, and uptime.

```bash
php bin/pulsar runtime:status
php bin/pulsar runtime:status --json
```

### `runtime:reload`

Trigger a graceful reload of the running worker.

```bash
php bin/pulsar runtime:reload
```

## Studio Integration

The runtime emits event types for observability:

| Event                    | When                           |
| ------------------------ | ------------------------------ |
| `RuntimeWorkerStart`     | Worker starts listening        |
| `RuntimeWorkerRecycle`   | Worker recycling (with reason) |
| `RuntimeRequestComplete` | After each request             |
| `RuntimeLeakWarning`     | Leak detector finds issues     |
| `RuntimeSchedulerMetric` | Periodic Fiber stats           |

Metrics registered in `MetricRegistry`:

- `runtime_requests_total` (Counter)
- `runtime_request_duration_ms` (Histogram)
- `runtime_memory_bytes` (Gauge)
- `runtime_worker_restarts_total` (Counter)
- `runtime_active_fibers` (Gauge)
- `runtime_slow_requests_total` (Counter, >1s threshold)

## Configuration Reference

All settings are in `config/runtime.php`. Environment variables override config file values.

| Key                      | Default     | Env Override                    | Description                                                              |
| ------------------------ | ----------- | ------------------------------- | ------------------------------------------------------------------------ |
| `driver`                 | `auto`      | `RUNTIME_DRIVER`                | Runtime driver (`auto`, `fpm`, `persistent`, `frankenphp`, `roadrunner`) |
| `host`                   | `127.0.0.1` | `RUNTIME_HOST`                  | Bind address                                                             |
| `port`                   | `8080`      | `RUNTIME_PORT`                  | Bind port                                                                |
| `max_requests`           | `10000`     | `RUNTIME_MAX_REQUESTS`          | Requests before worker recycle                                           |
| `memory_threshold_mb`    | `256`       | `RUNTIME_MEMORY_THRESHOLD_MB`   | Memory limit before recycle (MB)                                         |
| `time_limit_seconds`     | `7200`      | `RUNTIME_TIME_LIMIT_SECONDS`    | Max worker uptime (seconds)                                              |
| `keep_alive`             | `true`      | --                              | Enable HTTP/1.1 keep-alive                                               |
| `keep_alive_timeout`     | `15`        | --                              | Idle timeout between requests (s)                                        |
| `header_timeout_seconds` | `15`        | --                              | Slowloris header timeout (s)                                             |
| `body_timeout_seconds`   | `60`        | --                              | Body receive timeout (s)                                                 |
| `fiber_concurrency`      | `0`         | `RUNTIME_FIBER_CONCURRENCY`     | Fiber slots (0 = synchronous)                                            |
| `max_header_size`        | `8192`      | --                              | Max request header size (bytes)                                          |
| `max_body_size`          | `10485760`  | --                              | Max request body size (bytes)                                            |
| `add_date_header`        | `true`      | --                              | Add Date header to responses                                             |
| `drain_timeout_seconds`  | `30`        | `RUNTIME_DRAIN_TIMEOUT_SECONDS` | Graceful drain timeout (seconds)                                         |
| `health_endpoint`        | `true`      | --                              | Enable `/_health` endpoint                                               |

## Deployment Guides

For runtime-specific production deployment instructions, see:

- [FrankenPHP Deployment](deployment/frankenphp.md)
- [RoadRunner Deployment](deployment/roadrunner.md)
- [Persistent Runtime Deployment](deployment/persistent.md)
- [Health Monitoring](deployment/health-monitoring.md)
