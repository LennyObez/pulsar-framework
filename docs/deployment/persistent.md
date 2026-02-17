# Persistent Runtime Deployment

This guide covers deploying a Pulsar application with the built-in persistent HTTP runtime. The persistent runtime is a long-running HTTP/1.1 server using `ext-sockets` that boots the kernel once and handles many requests with per-request isolation.

## Prerequisites

- PHP 8.5+ with `ext-sockets` enabled
- A process supervisor (systemd, supervisord, Docker) for automatic restarts
- A reverse proxy (nginx, Caddy) for TLS termination in production

## Quick start

```bash
# Start with default settings (127.0.0.1:8080, synchronous)
php bin/pulsar runtime:serve

# Custom port with Fiber concurrency
php bin/pulsar runtime:serve --port 3000 --concurrency 64

# Bind to all interfaces (requires --public flag)
php bin/pulsar runtime:serve --host 0.0.0.0 --port 8080 --public
```

## Production architecture

```
Internet -> Reverse Proxy (TLS 1.3, HTTP/2, HTTP/3)
                | HTTP/1.1 over localhost
           Pulsar runtime:serve (one or more workers)
```

The persistent runtime does not terminate TLS. Always place it behind a reverse proxy in production.

## systemd Unit File

```ini
[Unit]
Description=Pulsar HTTP Runtime
After=network.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/app

ExecStart=/usr/bin/php bin/pulsar runtime:serve --port 8080 --concurrency 64
ExecReload=/bin/kill -USR1 $MAINPID

Restart=always
RestartSec=1

Environment=APP_ENV=production
Environment=RUNTIME_DRIVER=persistent

# Security hardening
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/www/app/var /var/www/app/storage
PrivateTmp=true

# Resource limits
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl enable pulsar-runtime
sudo systemctl start pulsar-runtime
```

Reload workers gracefully:

```bash
sudo systemctl reload pulsar-runtime
```

### Multiple workers

Run multiple worker instances on different ports behind a load balancer:

```ini
# /etc/systemd/system/pulsar-runtime@.service
[Unit]
Description=Pulsar HTTP Runtime (port %i)
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php bin/pulsar runtime:serve --port %i --concurrency 64
ExecReload=/bin/kill -USR1 $MAINPID
Restart=always
RestartSec=1

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable pulsar-runtime@8080
sudo systemctl enable pulsar-runtime@8081
sudo systemctl enable pulsar-runtime@8082
sudo systemctl start pulsar-runtime@8080 pulsar-runtime@8081 pulsar-runtime@8082
```

## supervisord Configuration

```ini
[program:pulsar-runtime]
command=/usr/bin/php bin/pulsar runtime:serve --port 8080 --concurrency 64
directory=/var/www/app
user=www-data
autostart=true
autorestart=true
startsecs=5
startretries=3
stopwaitsecs=30
stopsignal=SIGTERM
stdout_logfile=/var/log/pulsar/runtime.log
stderr_logfile=/var/log/pulsar/runtime-error.log
environment=APP_ENV="production",RUNTIME_DRIVER="persistent"
numprocs=1
```

For multiple workers:

```ini
[program:pulsar-runtime]
command=/usr/bin/php bin/pulsar runtime:serve --port 80%(process_num)02d --concurrency 64
directory=/var/www/app
user=www-data
autostart=true
autorestart=true
numprocs=3
process_name=%(program_name)s-%(process_num)02d
stopwaitsecs=30
stopsignal=SIGTERM
stdout_logfile=/var/log/pulsar/runtime-%(process_num)02d.log
stderr_logfile=/var/log/pulsar/runtime-%(process_num)02d-error.log
```

## Docker setup

### Dockerfile

```dockerfile
FROM php:8.5-cli

# Install ext-sockets and other extensions
RUN docker-php-ext-install sockets pdo_pgsql intl opcache

# Copy application
COPY . /app
WORKDIR /app

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Build framework caches
RUN php bin/pulsar optimize

# Expose HTTP port
EXPOSE 8080

# Health check
HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost:8080/_health || exit 1

CMD ["php", "bin/pulsar", "runtime:serve", "--host", "0.0.0.0", "--port", "8080", "--concurrency", "64", "--public"]
```

### docker-compose.yml

```yaml
services:
  app:
    build: .
    ports:
      - '8080:8080'
    environment:
      APP_ENV: production
      APP_DEBUG: 'false'
      RUNTIME_DRIVER: persistent
      RUNTIME_MAX_REQUESTS: '10000'
      RUNTIME_MEMORY_THRESHOLD_MB: '256'
      RUNTIME_FIBER_CONCURRENCY: '64'
    restart: unless-stopped
    healthcheck:
      test: ['CMD', 'curl', '-sf', 'http://localhost:8080/_health']
      interval: 15s
      timeout: 5s
      start_period: 10s
      retries: 3
```

## Nginx reverse proxy

### Single worker

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

    # WebSocket upgrade
    location /ws {
        proxy_pass http://pulsar;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

### Multiple workers (load balanced)

```nginx
upstream pulsar {
    server 127.0.0.1:8080;
    server 127.0.0.1:8081;
    server 127.0.0.1:8082;
    keepalive 32;
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
}
```

### Caddy reverse proxy

```caddyfile
example.com {
    reverse_proxy localhost:8080 localhost:8081 localhost:8082
}
```

## Fiber concurrency tuning

The `--concurrency` flag (or `fiber_concurrency` config) controls how many connections the worker handles concurrently using PHP Fibers.

| Setting       | Behavior                                              |
| ------------- | ----------------------------------------------------- |
| `0` (default) | Synchronous accept loop, one request at a time        |
| `1-32`        | Low concurrency, good for CPU-bound workloads         |
| `64-128`      | Moderate, good for mixed I/O and CPU                  |
| `256+`        | High, for I/O-heavy workloads (API gateways, proxies) |

Fibers provide I/O concurrency only. PHP remains single-threaded. Blocking database calls without async drivers do not benefit from higher concurrency. Monitor `runtime_active_fibers` and request latency to find the right setting.

## Memory threshold configuration

The persistent runtime recycles when any threshold is exceeded:

| Threshold    | Config Key            | Env Override                  | Default |
| ------------ | --------------------- | ----------------------------- | ------- |
| Max requests | `max_requests`        | `RUNTIME_MAX_REQUESTS`        | 10,000  |
| Memory limit | `memory_threshold_mb` | `RUNTIME_MEMORY_THRESHOLD_MB` | 256 MB  |
| Time limit   | `time_limit_seconds`  | `RUNTIME_TIME_LIMIT_SECONDS`  | 7,200s  |

After recycling, the process exits and the supervisor restarts it. Tune thresholds based on your application's memory profile. Monitor the `/_health` endpoint to observe memory growth patterns.

## Monitoring

### Health endpoint

The built-in `/_health` endpoint responds with worker status:

```bash
curl http://localhost:8080/_health
# {"status":"healthy","requests":4521,"memory_mb":48,"uptime_s":3600}
```

Use this for load balancer health checks. A `503` response means the worker is draining or shutting down - the load balancer should stop routing traffic to it.

### Prometheus integration

Pulsar registers runtime metrics in `MetricRegistry`. Expose them via your Prometheus exporter:

- `runtime_requests_total` -- total requests handled
- `runtime_request_duration_ms` -- request latency histogram
- `runtime_memory_bytes` -- current memory usage
- `runtime_worker_restarts_total` -- recycling events
- `runtime_active_fibers` -- concurrent Fiber count

### Log monitoring

The persistent runtime logs key events to the configured `LoggerInterface`:

- Worker start and stop
- Recycling events (with reason: `max_requests`, `memory_threshold`, `time_limit`, `fatal_error`)
- Request handler errors
- Leak detector warnings

## Troubleshooting

**"ext-sockets not loaded"**: Install the sockets extension (`apt install php-sockets` or enable in `php.ini`).

**"Address already in use"**: Another process is listening on the port. Check with `ss -tlnp | grep 8080`.

**Memory growing unboundedly**: Lower `max_requests` to recycle more frequently. Check for services that hold request state without implementing `ResettableInterface`.

**Tenant state leaking between requests**: Framework services that hold per-request state (`TenantContext`, `AuthManager`, `RequestContextHolder`, `FlagEvaluationLog`) implement `ResettableInterface` and are automatically reset by `RequestSandbox` between requests. If you create custom request-scoped services, implement `ResettableInterface` and register them with `RequestResetRegistry`.

**High p99 latency**: If using Fibers, check whether database queries block the event loop. Consider lowering `fiber_concurrency` or using async database drivers.
