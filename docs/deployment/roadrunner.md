# RoadRunner Deployment

This guide covers deploying a Pulsar application with RoadRunner. RoadRunner is a high-performance PHP application server that communicates with PHP workers via a binary protocol, providing configurable worker pools and multi-protocol support.

## Prerequisites

- PHP 8.5+
- RoadRunner binary (`rr`)
- `spiral/roadrunner-http` Composer package
- Pulsar application with `config/runtime.php` set to `driver => 'roadrunner'` or `'auto'`

## Installation

```bash
# Install RoadRunner binary
composer require spiral/roadrunner-cli --dev
vendor/bin/rr get-binary

# Install the HTTP worker SDK
composer require spiral/roadrunner-http
```

## Configuration

### .rr.yaml

Create a `.rr.yaml` file in the project root:

```yaml
version: '3'

server:
  command: 'php public/index.php'
  relay: pipes

http:
  address: '0.0.0.0:8080'
  middleware:
   - gzip
   - headers
  headers:
    response:
      X-Powered-By: 'Pulsar'
  pool:
    num_workers: 8
    max_jobs: 10000
    allocate_timeout: 60s
    destroy_timeout: 60s
    supervisor:
      watch_tick: 1s
      ttl: 7200s
      idle_ttl: 60s
      max_worker_memory: 256

  static:
    dir: 'public'
    forbid:
     - '.php'
     - '.env'

logs:
  mode: production
  level: info
  output: stderr

status:
  address: '127.0.0.1:2114'

metrics:
  address: '127.0.0.1:2112'
```

### Configuration Reference

| Setting                                  | Description                      | Default        |
| ---------------------------------------- | -------------------------------- | -------------- |
| `http.pool.num_workers`                  | Number of PHP worker processes   | CPU cores      |
| `http.pool.max_jobs`                     | Requests before worker recycle   | 0 (unlimited)  |
| `http.pool.supervisor.ttl`               | Max worker lifetime              | 0s (unlimited) |
| `http.pool.supervisor.max_worker_memory` | Memory limit per worker (MB)     | 0 (unlimited)  |
| `http.pool.allocate_timeout`             | Time to wait for a free worker   | 60s            |
| `http.pool.destroy_timeout`              | Time to wait for worker shutdown | 60s            |

### Aligning with Pulsar Config

Set RoadRunner pool settings to match your `config/runtime.php` values:

```yaml
http:
  pool:
    max_jobs: 10000 # matches max_requests
    supervisor:
      ttl: 7200s # matches time_limit_seconds
      max_worker_memory: 256 # matches memory_threshold_mb
```

Both Pulsar and RoadRunner enforce recycling limits. The first threshold reached triggers the recycle.

## Docker Setup

### Dockerfile

```dockerfile
FROM php:8.5-cli

# Install PHP extensions
RUN docker-php-ext-install pdo_pgsql intl opcache

# Install RoadRunner
COPY --from=ghcr.io/roadrunner-server/roadrunner:latest /usr/bin/rr /usr/local/bin/rr

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

CMD ["rr", "serve", "-c", ".rr.yaml"]
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
      RUNTIME_DRIVER: roadrunner
      RR_MODE: http
    restart: unless-stopped
    healthcheck:
      test: ['CMD', 'curl', '-sf', 'http://localhost:8080/_health']
      interval: 15s
      timeout: 5s
      start_period: 10s
      retries: 3

  # Reverse proxy for TLS termination
  caddy:
    image: caddy:latest
    ports:
     - '80:80'
     - '443:443'
     - '443:443/udp'
    volumes:
     - ./Caddyfile:/etc/caddy/Caddyfile
     - caddy_data:/data
    depends_on:
     - app

volumes:
  caddy_data:
```

## systemd Unit File

```ini
[Unit]
Description=Pulsar RoadRunner Worker
After=network.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/app

ExecStart=/usr/local/bin/rr serve -c /var/www/app/.rr.yaml
ExecReload=/bin/kill -USR1 $MAINPID

Restart=always
RestartSec=5

Environment=APP_ENV=production
Environment=RR_MODE=http

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

## supervisord Configuration

Alternative to systemd for environments that use supervisord:

```ini
[program:pulsar-roadrunner]
command=/usr/local/bin/rr serve -c /var/www/app/.rr.yaml
directory=/var/www/app
user=www-data
autostart=true
autorestart=true
startsecs=5
startretries=3
stopwaitsecs=30
stopsignal=SIGTERM
stdout_logfile=/var/log/pulsar/roadrunner.log
stderr_logfile=/var/log/pulsar/roadrunner-error.log
environment=APP_ENV="production",RR_MODE="http"
```

## Worker Pool Settings

### Sizing Guidelines

| Workload             | `num_workers`     | Notes                    |
| -------------------- | ----------------- | ------------------------ |
| CPU-bound            | CPU cores         | Avoid oversubscription   |
| I/O-bound (DB, APIs) | 2x-4x CPU cores   | Workers block on I/O     |
| Mixed                | 1.5x-2x CPU cores | Start here, benchmark up |

### Supervisor Settings

RoadRunner's built-in supervisor monitors worker health independently of Pulsar's recycling:

```yaml
http:
  pool:
    supervisor:
      watch_tick: 1s # How often to check workers
      ttl: 7200s # Max worker lifetime
      idle_ttl: 60s # Kill idle workers
      max_worker_memory: 256 # MB limit per worker
```

## PSR-7 Bridge

Pulsar's `RoadRunnerRuntime` converts between RoadRunner's PSR-7 request/response types and Pulsar's internal HTTP types via the `PsrBridge`. The bridge handles:

- `ServerRequestInterface` to Pulsar `Request` conversion
- Pulsar `Response` to `ResponseInterface` conversion
- Header normalization and body stream handling

No additional configuration is needed - the bridge is wired automatically when the RoadRunner runtime is detected.

## Graceful Reload

```bash
# Reload workers (zero-downtime)
rr reset

# Or send SIGUSR1
kill -USR1 $(pgrep rr)
```

During reload:

1. RoadRunner stops sending requests to the old workers
2. Pulsar workers enter draining state
3. In-flight requests complete (up to `drain_timeout_seconds`)
4. Old workers exit, new workers spawn with fresh state

## Monitoring

### RoadRunner Status

RoadRunner provides a built-in status endpoint:

```yaml
status:
  address: '127.0.0.1:2114'
```

```bash
# Check RoadRunner health
curl http://localhost:2114/health?plugin=http
```

### Prometheus Metrics

RoadRunner exposes Prometheus metrics natively:

```yaml
metrics:
  address: '127.0.0.1:2112'
```

```yaml
# prometheus.yml scrape config
scrape_configs:
 - job_name: 'roadrunner'
    static_configs:
     - targets: ['localhost:2112']

 - job_name: 'pulsar-health'
    metrics_path: '/_health'
    static_configs:
     - targets: ['localhost:8080']
```

### Pulsar Health Endpoint

The `/_health` endpoint provides per-worker health data. Behind a load balancer, each worker responds independently:

```bash
curl http://localhost:8080/_health
# {"status":"healthy","requests":4521,"memory_mb":48,"uptime_s":3600}
```

## Environment Variables

| Variable                        | Description                                     |
| ------------------------------- | ----------------------------------------------- |
| `RR_MODE`                       | Set to `http` (required for auto-detection)     |
| `RUNTIME_DRIVER`                | Set to `roadrunner` for explicit selection      |
| `RUNTIME_MAX_REQUESTS`          | Pulsar-level recycle threshold (default: 10000) |
| `RUNTIME_MEMORY_THRESHOLD_MB`   | Pulsar-level memory limit (default: 256)        |
| `RUNTIME_DRAIN_TIMEOUT_SECONDS` | Drain timeout during reload (default: 30)       |

## Reverse Proxy

RoadRunner does not terminate TLS. Place it behind a reverse proxy for production:

### Caddy

```caddyfile
example.com {
    reverse_proxy localhost:8080
}
```

### nginx

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
}
```

## Troubleshooting

**Workers not starting**: Verify `RR_MODE=http` is set. Check `.rr.yaml` syntax with `rr serve -c .rr.yaml --debug`.

**"Worker not ready" errors**: Increase `allocate_timeout` in `.rr.yaml`. Check application boot time with `php bin/pulsar optimize`.

**Memory growth**: Compare RoadRunner supervisor limits with Pulsar's `memory_threshold_mb`. The first threshold reached triggers recycle.
