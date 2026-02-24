# FrankenPHP Deployment

This guide covers deploying a Pulsar application with FrankenPHP in worker mode. FrankenPHP is a PHP application server built on Caddy that provides native HTTP/3, automatic TLS, and 103 Early Hints support.

## Prerequisites

- PHP 8.5+
- FrankenPHP binary (includes Caddy and embedded PHP)
- Pulsar application with `config/runtime.php` set to `driver => 'frankenphp'` or `'auto'`

## Caddyfile Configuration

FrankenPHP uses a Caddyfile to configure the web server. The `php_server` directive enables worker mode with `frankenphp_handle_request()`.

### Basic Worker Mode

```caddyfile
{
    frankenphp
    order php_server before file_server
}

example.com {
    root * /var/www/app/public

    php_server {
        worker /var/www/app/public/index.php
        num 4
    }
}
```

### With HTTP/3 and Early Hints

Caddy enables HTTP/3 (QUIC) and automatic TLS by default when serving a domain name. Early Hints are sent via the `frankenphp_early_hints()` API in your route handlers.

```caddyfile
{
    frankenphp
    order php_server before file_server
}

example.com {
    root * /var/www/app/public

    php_server {
        worker /var/www/app/public/index.php
        num 8
    }

    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
    }
}
```

### Development (Local TLS)

```caddyfile
{
    frankenphp
    order php_server before file_server
}

localhost {
    root * /var/www/app/public

    php_server {
        worker /var/www/app/public/index.php
        num 2
    }
}
```

Caddy automatically generates a local CA and TLS certificate for `localhost`.

## Docker Setup

### Dockerfile

```dockerfile
FROM dunglas/frankenphp:latest

# Install PHP extensions
RUN install-php-extensions \
    pdo_pgsql \
    redis \
    intl \
    opcache

# Copy application
COPY . /app
WORKDIR /app

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Build framework caches
RUN php bin/pulsar optimize

# Copy Caddyfile
COPY Caddyfile /etc/caddy/Caddyfile

# Expose ports (80 HTTP, 443 HTTPS, 443/udp HTTP/3)
EXPOSE 80 443 443/udp

# Health check using the built-in /_health endpoint
HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost/_health || exit 1
```

### docker-compose.yml

```yaml
services:
  app:
    build: .
    ports:
     - '80:80'
     - '443:443'
     - '443:443/udp'
    volumes:
     - caddy_data:/data
     - caddy_config:/config
    environment:
      APP_ENV: production
      APP_DEBUG: 'false'
      RUNTIME_DRIVER: frankenphp
      RUNTIME_MAX_REQUESTS: '10000'
      RUNTIME_MEMORY_THRESHOLD_MB: '256'
    restart: unless-stopped
    healthcheck:
      test: ['CMD', 'curl', '-sf', 'http://localhost/_health']
      interval: 15s
      timeout: 5s
      start_period: 10s
      retries: 3

volumes:
  caddy_data:
  caddy_config:
```

## systemd Unit File

For bare-metal deployments without Docker:

```ini
[Unit]
Description=Pulsar FrankenPHP Worker
After=network.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/app

ExecStart=/usr/local/bin/frankenphp run --config /etc/caddy/Caddyfile
ExecReload=/bin/kill -USR1 $MAINPID

Restart=always
RestartSec=5

# Security hardening
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/www/app/var /var/www/app/storage
PrivateTmp=true

# Resource limits
LimitNOFILE=65535
MemoryMax=512M

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl enable pulsar-frankenphp
sudo systemctl start pulsar-frankenphp
```

## Early Hints

FrankenPHP supports 103 Early Hints natively. Use the `earlyHints()` method on the FrankenPHP runtime to preload critical resources before the response is ready:

```php
// In a route handler or middleware
$runtime->earlyHints([
    'Link' => '</css/app.css>; rel=preload; as=style',
]);

$runtime->earlyHints([
    'Link' => '</js/app.js>; rel=preload; as=script',
]);
```

The browser receives the 103 response immediately and starts fetching the linked resources while the server computes the full response.

## Worker Pool Sizing

The `num` directive in the Caddyfile controls the number of PHP worker threads:

| Workload             | Recommendation                     |
| -------------------- | ---------------------------------- |
| CPU-bound            | `num` = number of CPU cores        |
| I/O-bound (DB, APIs) | `num` = 2x-4x CPU cores            |
| Mixed                | Start with CPU cores, benchmark up |

Monitor worker utilization via the `/_health` endpoint and Prometheus metrics to tune the pool size.

## Graceful Reload

FrankenPHP supports graceful reload via Caddy's built-in mechanisms:

```bash
# Reload configuration (zero-downtime)
frankenphp reload --config /etc/caddy/Caddyfile

# Or send SIGUSR1 to trigger worker drain + recycle
kill -USR1 $(pgrep frankenphp)
```

During reload, Pulsar workers enter the draining state, finish in-flight requests (up to `drain_timeout_seconds`), and recycle. New workers start with fresh state.

## Monitoring

### Prometheus Metrics

Caddy exposes Prometheus metrics at `localhost:2019/metrics` by default. Combined with Pulsar's `/_health` endpoint and `MetricRegistry` metrics, you get full observability:

```yaml
# prometheus.yml scrape config
scrape_configs:
 - job_name: 'caddy'
    static_configs:
     - targets: ['localhost:2019']

 - job_name: 'pulsar-health'
    metrics_path: '/_health'
    static_configs:
     - targets: ['localhost:443']
    scheme: https
```

### Log Output

FrankenPHP logs to stderr in JSON format by default. Configure log aggregation with your preferred tool (Loki, Fluentd, CloudWatch).

## Environment Variables

| Variable                        | Description                                     |
| ------------------------------- | ----------------------------------------------- |
| `RUNTIME_DRIVER`                | Set to `frankenphp` for explicit selection      |
| `RUNTIME_MAX_REQUESTS`          | Requests before worker recycle (default: 10000) |
| `RUNTIME_MEMORY_THRESHOLD_MB`   | Memory limit before recycle (default: 256)      |
| `RUNTIME_DRAIN_TIMEOUT_SECONDS` | Drain timeout during reload (default: 30)       |
| `CADDY_GLOBAL_OPTIONS`          | Additional Caddy global options                 |

## Troubleshooting

**Workers not starting**: Verify `frankenphp_handle_request()` is available. Check that the `worker` directive points to the correct entry point (`public/index.php`).

**High memory usage**: Reduce `num` workers or lower `RUNTIME_MEMORY_THRESHOLD_MB`. Check the `/_health` endpoint for per-worker memory stats.

**TLS certificate errors**: For local development, run `frankenphp trust` to install the local CA. For production, ensure DNS records point to the server before starting.
