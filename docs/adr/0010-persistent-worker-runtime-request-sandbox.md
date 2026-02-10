# ADR-0010: Persistent Worker Runtime with Request Sandbox Isolation

## Status

Accepted

## Context

Traditional PHP deployment uses PHP-FPM: each request boots the application from scratch, handles the request, and terminates. This model provides perfect isolation (process exit cleans everything) but pays the full boot cost on every request.

For high-throughput, low-latency workloads, persistent runtimes (Swoole, RoadRunner, FrankenPHP) boot the application once and handle many requests in a long-lived process. However, persistent runtimes introduce state leakage risks: services, static variables, and global state from one request can bleed into the next.

Pulsar needs an optional persistent runtime that provides the performance benefits of boot-once execution without sacrificing the isolation guarantees that regulated domains require.

## Decision

Ship an optional persistent worker runtime (`runtime:serve`) as a built-in command. The default PHP-FPM deployment path remains unchanged and fully supported.

### Architecture

1. **Boot once.** The kernel boots a single time when the worker starts. Config loading, extension registration, container compilation, and route registration happen once.
2. **Accept connections.** The worker accepts HTTP connections on a configurable host/port using `ext-sockets`.
3. **Request sandbox.** `RequestSandbox` enforces per-request isolation using a `RequestResetRegistry` populated during kernel boot. The registry maintains two explicit lists:

- **Evictable services** (`registry->evictableIds`): Service IDs that are removed from the container (`forgetInstance`) between requests. These are request-bound services (e.g., `SecurityContext`) that middleware recreates for each new request. Services are registered as evictable during boot via `RequestResetRegistry::registerEvictable()`.
- **Resettable services** (`registry->resettableIds`): Service IDs of stateful singletons implementing `ResettableInterface` (e.g., `TenantContext`, `FlagEvaluationLog`). Their `resetRequestState()` method is called between requests. Services are registered via `RequestResetRegistry::registerResettable()`.
- **Leak detection.** `LeakDetector` monitors unreleased resources and memory growth, emitting warnings via Studio.
- **Execution order is deterministic:** (1) evict, (2) reset, (3) leak check.

4. **Graceful recycling.** Workers shut down gracefully after configurable thresholds (request count, memory usage, uptime) and are restarted by a process supervisor.
5. **Optional Fiber concurrency.** The `--concurrency N` flag enables Fiber-based connection multiplexing for accepting multiple connections. Individual request handling remains sequential (see ADR-0005).

### Safety constraints

- The `--public` flag is required to bind to non-loopback interfaces, preventing accidental public exposure.
- TLS termination is delegated to a reverse proxy - the worker speaks plaintext HTTP only.
- Keep-alive connections are supported with configurable timeouts.

## Consequences

### Positive

- **Eliminates per-request boot cost.** Config loading, container compilation, and route registration happen once. Subsequent requests pay only the dispatch cost.
- **Controlled isolation.** `RequestSandbox` provides explicit, auditable state cleanup - unlike process-level isolation, which is implicit and unverifiable.
- **Leak detection.** Memory growth and resource leaks are detected and reported, not silently accumulated until OOM.
- **Graceful degradation.** Recycling thresholds prevent long-running workers from accumulating unbounded state.

### Negative

- **State leakage risk.** Any service that holds request state and is not registered in the `RequestResetRegistry` can leak between requests. This requires developer discipline and testing. A key integration test scenario: two sequential requests must not share `SecurityContext`, tenant context, or feature flag evaluation state.
- **Requires process supervisor.** Unlike PHP-FPM (self-managing), the persistent worker needs systemd, supervisord, or Docker for restart management.
- **No built-in TLS.** Production deployments require a reverse proxy (nginx, Caddy) for HTTPS.

### Neutral

- **PHP-FPM remains the default.** The persistent runtime is opt-in. Applications that do not need the performance characteristics can ignore it entirely.
- **Not a replacement for RoadRunner/Swoole.** The built-in runtime is intentionally simple - no coroutine scheduler, no built-in HTTP/2, no WebSocket support. Applications needing those features should use dedicated runtimes with Pulsar adapters.
