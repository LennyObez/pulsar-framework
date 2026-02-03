# Persistent Worker HTTP Runtime

Pulsar ships an **optional** persistent worker runtime that boots the kernel once and handles many HTTP requests, eliminating per-request bootstrap overhead. The default PHP-FPM path remains unchanged.

## Quick Start

```bash
# Start with default settings (127.0.0.1:8080, synchronous)
php bin/pulsar runtime:serve

# Custom port with Fiber concurrency
php bin/pulsar runtime:serve --port 3000 --concurrency 64

# Bind to all interfaces (requires --public flag)
php bin/pulsar runtime:serve --host 0.0.0.0 --port 8080 --public
```

## Requirements

- **PHP 8.5+** with `ext-sockets` enabled
- A process supervisor (systemd, supervisord, Docker) for automatic restarts
- A reverse proxy for TLS termination in production

## Architecture

### Request Flow

```
Persistent Worker:
  Worker start → Kernel::boot() (once)
    → accept connection
      → HttpRequestParser::parse() → Request
      → RequestSandbox::beforeRequest()
      → Kernel::handle(request) → Response
      → RequestSandbox::afterRequest()
      → HttpResponseSerializer::serialize() → socket
      → keep-alive? loop : close
    → recycle threshold? → graceful shutdown → restart
```

### FPM vs Persistent

| Aspect            | PHP-FPM                  | Persistent Runtime         |
| ----------------- | ------------------------ | -------------------------- |
| Boot              | Every request            | Once per worker lifecycle  |
| State isolation   | Process-level            | RequestSandbox per request |
| Concurrency       | php-fpm process pool     | Fiber scheduler            |
| TLS               | Handled by web server    | Reverse proxy required     |
| Memory management | Process exit cleans up   | Leak detection + recycling |
| Best for          | Shared hosting, simplest | High-throughput, low-p99   |

## Safety Rules

### Per-Request Isolation

The `RequestSandbox` enforces strict isolation between requests:

1. **Eviction**: Request-bound services (e.g., `SecurityContext`) are removed from the container between requests. Middleware recreates them for each new request.
2. **Reset**: Stateful singletons implementing `ResettableInterface` (e.g., `TenantContext`, `FlagEvaluationLog`) have their state cleared.
3. **Leak detection**: The `LeakDetector` monitors unreleased resources and memory growth, emitting warnings via Studio.

**Hard invariant**: Two sequential requests MUST never share identity, session, or tenant state.

### Services That Persist (safe)

- Container, Router, Config DTOs
- MetricRegistry, Logger, Database connections
- Extension registrations

### Services That Reset (ResettableInterface)

- `TenantContext` — clears current tenant
- `FlagEvaluationLog` — clears evaluation history
- `AuthManager` — defense-in-depth (currently no-op)

### Services That Are Evicted

- `SecurityContext` — holds readonly `Request` + cached identity; must be recreated per request

## Configuration

Config file: `config/runtime.php`

| Key                      | Default     | Env Override                  | Description                       |
| ------------------------ | ----------- | ----------------------------- | --------------------------------- |
| `host`                   | `127.0.0.1` | `RUNTIME_HOST`                | Bind address                      |
| `port`                   | `8080`      | `RUNTIME_PORT`                | Bind port                         |
| `max_requests`           | `10000`     | `RUNTIME_MAX_REQUESTS`        | Requests before worker recycle    |
| `memory_threshold_mb`    | `256`       | `RUNTIME_MEMORY_THRESHOLD_MB` | Memory limit before recycle (MB)  |
| `time_limit_seconds`     | `7200`      | `RUNTIME_TIME_LIMIT_SECONDS`  | Max worker uptime (seconds)       |
| `keep_alive`             | `true`      | —                             | Enable HTTP/1.1 keep-alive        |
| `keep_alive_timeout`     | `15`        | —                             | Idle timeout between requests (s) |
| `header_timeout_seconds` | `15`        | —                             | Slowloris header timeout (s)      |
| `body_timeout_seconds`   | `60`        | —                             | Body receive timeout (s)          |
| `fiber_concurrency`      | `0`         | `RUNTIME_FIBER_CONCURRENCY`   | Fiber slots (0 = synchronous)     |
| `max_header_size`        | `8192`      | —                             | Max request header size (bytes)   |
| `max_body_size`          | `10485760`  | —                             | Max request body size (bytes)     |
| `add_date_header`        | `true`      | —                             | Add Date header to responses      |

## Command Reference

```
runtime:serve [options]

Options:
  --host           Address to bind (default: from config)
  --port           Port to listen on (default: from config)
  --max-requests   Max requests before recycling (default: from config)
  --memory         Memory threshold in MB (default: from config)
  --timeout        Time limit in seconds (default: from config)
  --concurrency    Fiber concurrency, 0=sync (default: from config)
  --public         Required to bind to non-loopback addresses
```

### The `--public` Flag

By default, the runtime only binds to loopback addresses (`127.0.0.1`, `::1`, `localhost`). To bind to any other address (e.g., `0.0.0.0`), you must pass `--public` to acknowledge that:

- This server does **not** terminate TLS
- It should sit behind a reverse proxy in production
- The address may be accessible on the network

## Protocol Support

### HTTP/1.1

- Full HTTP/1.1 origin server
- Keep-alive connections (configurable)
- Request pipelining (sequential, in-order)
- Chunked request decoding with size limits
- `Content-Length` always present in responses

### Chunked Requests

- `Transfer-Encoding: chunked` is fully supported
- Decoded body buffered up to `max_body_size`
- Chunk extensions longer than 256 bytes are rejected
- Both `Transfer-Encoding` and `Content-Length` present → 400
- Non-`chunked` Transfer-Encoding → 501

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
- Upgrade handlers receive `UpgradeContext` (Logger, Metrics, Config only — no container)
- Request sandbox cleanup runs before connection handoff

## Fiber Concurrency

When `fiber_concurrency > 0`, the runtime uses a cooperative Fiber scheduler:

- One Fiber per accepted connection
- Main loop uses `socket_select()` to find readable sockets
- Fibers suspend when I/O would block
- Backpressure: stops accepting when at concurrency limit

### Important Expectations

- Fibers provide **I/O concurrency only** — PHP remains single-threaded
- Blocking DB calls without async drivers won't benefit from Fibers
- The value is in concurrent socket I/O (accept + read + write overlap)
- `socket_select()` behavior differs on Windows — test on your target platform

### Disabled by Default

Set `fiber_concurrency = 0` (default) for a synchronous accept loop. This is simpler and sufficient for most workloads behind a load balancer.

## Worker Recycling

Workers automatically recycle when any threshold is exceeded:

- **Max requests**: Prevents unbounded memory growth from per-request allocations
- **Memory threshold**: Hard limit on RSS growth
- **Time limit**: Guards against long-lived state drift

On recycle: drain active connections → `Kernel::shutdown()` → exit. The process supervisor restarts the worker.

### Fatal Error Containment

A `register_shutdown_function` handler catches fatal errors (`E_ERROR`, `E_CORE_ERROR`, `E_COMPILE_ERROR`), emits a Studio recycle event, and exits non-zero so the process supervisor restarts the worker. No corrupt state persists.

## Studio Integration

The runtime emits 5 event types:

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

## Production Deployment

### Recommended Stack

```
Internet → Reverse Proxy (TLS 1.3, HTTP/2, HTTP/3)
                ↓ HTTP/1.1 over localhost
           Pulsar runtime:serve (one or more workers)
```

### Example: Caddy Reverse Proxy

```caddyfile
example.com {
    reverse_proxy localhost:8080
}
```

### Example: nginx

```nginx
upstream pulsar {
    server 127.0.0.1:8080;
}

server {
    listen 443 ssl http2;
    server_name example.com;

    ssl_certificate     /etc/ssl/cert.pem;
    ssl_certificate_key /etc/ssl/key.pem;

    location / {
        proxy_pass http://pulsar;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /ws {
        proxy_pass http://pulsar;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

### Example: systemd Unit

```ini
[Unit]
Description=Pulsar HTTP Runtime
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php bin/pulsar runtime:serve --port 8080 --concurrency 64
Restart=always
RestartSec=1

[Install]
WantedBy=multi-user.target
```

## Implementing ResettableInterface

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

Then register in the `RequestResetRegistry` during kernel boot.

## Explicit Non-Goals (rc.6)

- Native QUIC / HTTP/3 implementation
- Native TLS termination
- Streaming request bodies to handlers
- HTTP/2 multiplexing
