# Health Monitoring

This guide covers health checking, monitoring, and observability for Pulsar applications running on persistent runtimes (Persistent, FrankenPHP, RoadRunner).

## Health Endpoint

All persistent runtimes serve a `/_health` endpoint that bypasses the kernel entirely. It responds with the worker's current state, request count, memory usage, and uptime.

### Request

```
GET /_health HTTP/1.1
Host: localhost:8080
```

### Response (Healthy)

```http
HTTP/1.1 200 OK
Content-Type: application/json

{
  "status": "healthy",
  "requests": 4521,
  "memory_mb": 48,
  "uptime_s": 3600
}
```

### Response (Draining)

```http
HTTP/1.1 503 Service Unavailable
Content-Type: application/json

{
  "status": "draining",
  "requests": 9998,
  "memory_mb": 245,
  "uptime_s": 7100
}
```

### Response Fields

| Field       | Type   | Description                                      |
| ----------- | ------ | ------------------------------------------------ |
| `status`    | string | `healthy`, `draining`, or `shutting_down`        |
| `requests`  | int    | Total requests handled by this worker since boot |
| `memory_mb` | int    | Current memory usage in megabytes                |
| `uptime_s`  | int    | Seconds since the worker started                 |

### HTTP Status Codes

| Worker State  | HTTP Status               | Meaning                                |
| ------------- | ------------------------- | -------------------------------------- |
| Healthy       | `200 OK`                  | Worker is accepting requests           |
| Draining      | `503 Service Unavailable` | Worker is finishing in-flight requests |
| Shutting down | `503 Service Unavailable` | Worker is about to exit                |

### Disabling

If your infrastructure uses a custom health-check path, disable the built-in endpoint in `config/runtime.php`:

```php
'health_endpoint' => false,
```

## Docker Health Checks

### Persistent Runtime

```dockerfile
HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost:8080/_health || exit 1
```

### FrankenPHP

```dockerfile
HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost/_health || exit 1
```

### RoadRunner

```dockerfile
HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost:8080/_health || exit 1
```

### docker-compose Health Check

```yaml
services:
  app:
    healthcheck:
      test: ['CMD', 'curl', '-sf', 'http://localhost:8080/_health']
      interval: 15s
      timeout: 5s
      start_period: 10s
      retries: 3
```

### Without curl

If `curl` is not available in the container, use PHP:

```dockerfile
HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r "exit(json_decode(file_get_contents('http://localhost:8080/_health'))?->status === 'healthy' ? 0 : 1);"
```

## Prometheus Integration

### Pulsar Metrics

Pulsar registers runtime metrics in `MetricRegistry`. These are available through your Prometheus exporter:

| Metric                          | Type      | Description                     |
| ------------------------------- | --------- | ------------------------------- |
| `runtime_requests_total`        | Counter   | Total requests handled          |
| `runtime_request_duration_ms`   | Histogram | Request latency distribution    |
| `runtime_memory_bytes`          | Gauge     | Current worker memory usage     |
| `runtime_worker_restarts_total` | Counter   | Worker recycle events           |
| `runtime_active_fibers`         | Gauge     | Active Fiber connections        |
| `runtime_slow_requests_total`   | Counter   | Requests exceeding 1s threshold |

### Scrape Configuration

```yaml
# prometheus.yml
scrape_configs:
  # Pulsar health endpoint (per-worker)
  - job_name: 'pulsar'
    metrics_path: '/_health'
    static_configs:
      - targets:
          - 'app-1:8080'
          - 'app-2:8080'
          - 'app-3:8080'

  # RoadRunner native metrics (if using RoadRunner)
  - job_name: 'roadrunner'
    static_configs:
      - targets: ['localhost:2112']

  # Caddy metrics (if using FrankenPHP or Caddy reverse proxy)
  - job_name: 'caddy'
    static_configs:
      - targets: ['localhost:2019']
```

### Alerting Rules

```yaml
# alerts.yml
groups:
  - name: pulsar_runtime
    rules:
      # Worker memory approaching threshold
      - alert: PulsarWorkerHighMemory
        expr: pulsar_health_memory_mb > 200
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: 'Pulsar worker memory high ({{ $value }}MB)'

      # Worker draining (about to recycle)
      - alert: PulsarWorkerDraining
        expr: pulsar_health_status != 1
        for: 1m
        labels:
          severity: info
        annotations:
          summary: 'Pulsar worker is draining'

      # No healthy workers
      - alert: PulsarNoHealthyWorkers
        expr: count(pulsar_health_status == 1) == 0
        for: 30s
        labels:
          severity: critical
        annotations:
          summary: 'No healthy Pulsar workers available'
```

## Load Balancer Integration

### Health Check Behavior

Load balancers should use the `/_health` endpoint to determine routing:

- **200 OK**: Worker is healthy, route traffic to it
- **503 Service Unavailable**: Worker is draining or shutting down, stop routing new requests

This enables zero-downtime deploys and graceful worker recycling.

### nginx Upstream Health

```nginx
upstream pulsar {
    server 127.0.0.1:8080 max_fails=3 fail_timeout=15s;
    server 127.0.0.1:8081 max_fails=3 fail_timeout=15s;
    server 127.0.0.1:8082 max_fails=3 fail_timeout=15s;
}
```

nginx does not natively poll health endpoints in the open-source version. Use the commercial `health_check` directive or rely on passive health checks (tracking failed responses).

### AWS ALB

Configure a target group health check:

| Setting             | Value        |
| ------------------- | ------------ |
| Protocol            | HTTP         |
| Path                | `/_health`   |
| Port                | Traffic port |
| Healthy threshold   | 2            |
| Unhealthy threshold | 3            |
| Timeout             | 5s           |
| Interval            | 15s          |
| Success codes       | 200          |

### Kubernetes

```yaml
apiVersion: v1
kind: Pod
spec:
  containers:
    - name: pulsar
      livenessProbe:
        httpGet:
          path: /_health
          port: 8080
        initialDelaySeconds: 10
        periodSeconds: 15
        timeoutSeconds: 5
        failureThreshold: 3
      readinessProbe:
        httpGet:
          path: /_health
          port: 8080
        initialDelaySeconds: 5
        periodSeconds: 10
        timeoutSeconds: 5
        failureThreshold: 1
```

The readiness probe removes the pod from the service when the worker returns `503` (draining), while the liveness probe restarts the pod if the worker becomes unresponsive.

## Graceful Reload Procedure

### Triggering a Reload

```bash
# Via POSIX signal
kill -USR1 <worker-pid>

# Via CLI
php bin/pulsar runtime:reload

# Via systemd
sudo systemctl reload pulsar-runtime
```

### Reload Lifecycle

1. Worker receives `SIGUSR1`
2. Status changes to `draining`
3. Health endpoint returns `503` (load balancer stops routing)
4. In-flight requests complete (up to `drain_timeout_seconds`)
5. Worker exits
6. Process supervisor restarts a fresh worker
7. New worker boots, health endpoint returns `200`

### Drain Timeout

Configure how long to wait for in-flight requests during a reload:

```php
// config/runtime.php
'drain_timeout_seconds' => 30,
```

Or via environment variable:

```bash
RUNTIME_DRAIN_TIMEOUT_SECONDS=30
```

After the drain timeout, remaining requests are forcefully terminated and the worker exits.

## Studio Events

The runtime emits structured events for observability:

| Event                    | Trigger                    | Data                                  |
| ------------------------ | -------------------------- | ------------------------------------- |
| `RuntimeWorkerStart`     | Worker starts listening    | host, port, concurrency, thresholds   |
| `RuntimeWorkerRecycle`   | Worker recycling           | reason, request count, memory, uptime |
| `RuntimeRequestComplete` | After each request         | path, status, duration, memory delta  |
| `RuntimeLeakWarning`     | Leak detector finds issues | resource type, memory growth          |
| `RuntimeSchedulerMetric` | Periodic (Fiber runtime)   | active fibers, pending, completed     |

## Logging

All persistent runtimes log key events via `LoggerInterface`:

```
INFO  Pulsar runtime listening on 127.0.0.1:8080
INFO  Worker recycling: max_requests (requests: 10000, memory: 48MB, uptime: 3600s)
WARN  Memory growth detected: 2457600 bytes (threshold: 2097152 bytes)
ERROR Request handler error: Division by zero (path: /api/calculate)
INFO  Pulsar runtime stopped
```

Configure log output through your application's logging configuration. In production, aggregate logs with Loki, Fluentd, ELK, or CloudWatch for centralized monitoring.
