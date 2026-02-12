# Observability

Pulsar provides a built-in observability suite: metrics collection, distributed tracing, and error tracking. All components are homegrown with no external service dependencies.

## Metrics

### Metric types

Three metric types are available via `MetricType` enum:

| Type      | Class                                    | Description                                     |
| --------- | ---------------------------------------- | ----------------------------------------------- |
| Counter   | `Pulsar\Observability\Metrics\Counter`   | Monotonic incrementing value                    |
| Gauge     | `Pulsar\Observability\Metrics\Gauge`     | Bidirectional value (set, increment, decrement) |
| Histogram | `Pulsar\Observability\Metrics\Histogram` | Bucket-based distribution tracking              |

### MetricRegistry

Central store for all metrics. Provides create-or-return semantics - requesting the same name and type returns the existing instance. Requesting the same name with a different type throws `MetricsException`.

```php
$registry = new MetricRegistry();

$counter = $registry->counter('http_requests_total', 'Total HTTP requests');
$gauge = $registry->gauge('connections_active', 'Active connections');
$histogram = $registry->histogram('request_duration_seconds', 'Request duration');
```

### Labels

Metrics support dimensional labels via `LabelSet`:

```php
use Pulsar\Observability\Metrics\LabelSet;

$labels = new LabelSet(['method' => 'GET', 'path' => '/api/users']);
$counter->increment($labels);
```

Label sets produce a deterministic string key (sorted by key name) for internal map lookups.

### Counter

Monotonic counter that rejects negative increments:

```php
$counter->increment(); // +1 with default labels
$counter->increment(new LabelSet(['method' => 'GET']), 5.0);
$counter->value(); // get value for default labels
$counter->values(); // all label-key => value pairs
```

### Gauge

Bidirectional numeric value:

```php
$gauge->set(42.5);
$gauge->increment(); // +1
$gauge->decrement(new LabelSet(['pool' => 'main']), 3.0);
```

### Histogram

Records observed values into configurable buckets. Default boundaries follow Prometheus conventions:

```php
[0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0]
```

Usage:

```php
$histogram = new Histogram('duration', boundaries: [0.1, 0.5, 1.0, 5.0]);
$histogram->observe(0.3);
$histogram->observe(2.1, new LabelSet(['endpoint' => '/api']));

$histogram->count(); // observation count
$histogram->sum(); // sum of observed values
$histogram->buckets(); // boundary => cumulative count
```

### OpenMetrics exporter

`OpenMetricsExporter` renders the entire registry as OpenMetrics text exposition format 0.0.4:

```php
$exporter = new OpenMetricsExporter($registry);
$text = $exporter->export();
```

Output includes `# HELP`, `# TYPE`, sample lines with labels, and histogram `_bucket`/`_sum`/`_count` series. The exporter is registered as a route at the configured endpoint (default `/metrics`) when the metrics exporter is enabled.

## Tracing

### Core concepts

Distributed tracing follows the W3C Trace Context specification:

- **TraceId** - 128-bit hex identifier (32 chars) for the entire trace
- **SpanId** - 64-bit hex identifier (16 chars) for a single operation
- **TraceContext** - Immutable value object holding traceId, spanId, and trace flags
- **Span** - Mutable lifecycle object representing a timed operation

### Creating spans

```php
use Pulsar\Observability\Tracing\{Span, TraceContext, SpanStatus};

$context = TraceContext::create(); // new root trace
$span = new Span('database.query', $context);

$span->setAttribute('db.statement', 'SELECT ...');
$span->setAttribute('db.system', 'postgresql');

// ... perform work ...

$span->setStatus(SpanStatus::Ok);
$span->end(); // records end time; idempotent
```

### Trace context propagation

W3C `traceparent` header parsing and serialization:

```php
use Pulsar\Observability\Tracing\W3CTraceContextParser;

// Parse incoming header
$context = W3CTraceContextParser::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

// Create child span in same trace
$child = $context->createChild();

// Serialize for outgoing request
$header = W3CTraceContextParser::serialize($child);
// "00-4bf92f3577b34da6a3ce929d0e0e4736-{new-span-id}-01"
```

### InMemorySpanCollector

Ring-buffer span collector implementing `SpanProcessorInterface`:

```php
$collector = new InMemorySpanCollector(maxSpans: 1000);

$span->end();
$collector->onEnd($span);

$collector->spans(); // all collected spans
$collector->spansByTraceId($traceId); // filter by trace
$collector->count(); // number of collected spans
```

Oldest spans are evicted when the buffer exceeds capacity.

## Error tracking

### Error fingerprinting

Errors are grouped by a deterministic SHA-256 fingerprint derived from:

```
{exception class}|{message}|{file}|{line}
```

This produces stable groups - the same error at the same location always maps to the same fingerprint.

### ErrorEvent

Readonly value object capturing a single error occurrence:

```php
use Pulsar\Observability\ErrorTracking\ErrorEvent;

$event = ErrorEvent::fromThrowable($exception, context: ['user_id' => 123]);
```

Each event records: exception class, message, code, file, line, stack trace, scrubbed context, timestamp, and optional trace ID for correlation with distributed traces.

### ErrorAggregator

Groups errors by fingerprint, maintains occurrence counts, and caps stored events:

```php
use Pulsar\Observability\ErrorTracking\ErrorAggregator;

$aggregator = new ErrorAggregator(maxGroups: 500);
$aggregator->capture($event);

$groups = $aggregator->groups(); // sorted by lastSeen descending
```

When the number of groups exceeds `maxGroups`, the oldest group (by last seen) is evicted.

### Sensitive data scrubbing

`SensitiveDataScrubber` recursively scrubs arrays, replacing values for keys matching sensitive field names:

```php
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

$scrubber = new SensitiveDataScrubber(additionalFields: ['custom_secret']);

$scrubbed = $scrubber->scrub(['password' => 'hunter2', 'name' => 'Alice']);
// ['password' => '[REDACTED]', 'name' => 'Alice']

$headers = $scrubber->scrubHeaders(['Authorization' => 'Bearer xxx', 'Accept' => 'json']);
// ['Authorization' => '[REDACTED]', 'Accept' => 'json']
```

Default sensitive fields: password, token, secret, api_key, authorization, credential, credit_card, ssn, private_key, access_token, refresh_token.

Default sensitive headers: Authorization, Cookie, Set-Cookie, X-API-Key.

## Middleware

### TracingMiddleware

Automatically instruments HTTP requests with distributed tracing:

1. Parses incoming `traceparent` header (if present)
2. Creates a root span named `"HTTP {METHOD} {path}"`
3. Attaches trace context and root span to request attributes (`_trace_context`, `_root_span`)
4. Sets span status based on response status code
5. Adds `traceparent` header to response for propagation
6. Respects configurable sampling rate

### MetricsMiddleware

Automatically records HTTP metrics:

- `pulsar_http_requests_total` - Counter with labels: method, path, status
- `pulsar_http_request_duration_seconds` - Histogram of request durations
- `pulsar_http_errors_total` - Counter for 5xx responses with labels: method, path

### Middleware ordering

TracingMiddleware is registered as the outermost middleware (first in, last out) to ensure spans cover the full request lifecycle. MetricsMiddleware is registered inner to tracing.

## Configuration

Configuration is via `config/observability.php`:

```php
return [
    'log_level' => 'debug',
    'log_channel' => 'app',
    'metrics' => [
        'enabled' => true,
        'exporters' => [
            'openmetrics' => [
                'enabled' => false,
                'endpoint' => '/metrics',
            ],
        ],
    ],
    'tracing' => [
        'enabled' => false,
        'sampling_rate' => 0.1,
    ],
    'error_tracking' => [
        'enabled' => true,
        'max_groups' => 500,
        'max_recent_events_per_group' => 5,
        'sensitive_fields' => [],
    ],
];
```

### Config DTOs

Each section maps to a typed readonly DTO:

- `MetricsConfig` - `enabled`, `exporterEnabled`, `exporterEndpoint`
- `TracingConfig` - `enabled`, `samplingRate`
- `ErrorTrackingConfig` - `enabled`, `maxGroups`, `maxRecentEventsPerGroup`, `sensitiveFields`

These are composed into `ObservabilityConfig` and built via `fromArray()` factories.

## Diagnostics

When debug mode is enabled, a diagnostics viewer is available at `/_pulsar/diagnostics`. It renders a dark-themed HTML page showing:

- Registered metrics with current values
- Error groups with occurrence counts and recent events
- Recent trace spans

This endpoint is for local development only and is not registered in production mode.

## Integration with ExceptionHandler

When error tracking is enabled, the `ExceptionHandler` automatically:

1. Scrubs sensitive data from request context
2. Creates an `ErrorEvent` from the thrown exception
3. Links the trace ID from the current request (if tracing is active)
4. Captures the event into the `ErrorAggregator`

This happens transparently alongside existing error handling behavior.

## Kernel boot pipeline

The observability subsystems are initialized during kernel boot in this order:

```
Config -> Logger -> Tracer -> Metrics -> ErrorTracker -> ExceptionHandler -> DiagnosticsRoute -> Extensions
```

This ensures tracing is available before metrics (so metrics middleware can access trace context), and error tracking is available before the exception handler is constructed.

## Observability events

Studio collects structured events from the framework runtime. Each event type has a typed payload DTO and is wrapped in an `EventEnvelope` with common metadata.

### Event types

| Event Type        | Enum Value          | Payload DTO             | Collector                |
| ----------------- | ------------------- | ----------------------- | ------------------------ |
| HTTP Request      | `http.request`      | `HttpRequestPayload`    | `HttpCollector`          |
| HTTP Response     | `http.response`     | `HttpResponsePayload`   | `HttpCollector`          |
| Database Query    | `database.query`    | `DatabaseQueryPayload`  | `InstrumentedConnection` |
| Cache Operation   | `cache.operation`   | `CacheOperationPayload` | (future)                 |
| Job Queued        | `job.queued`        | `JobPayload`            | (future)                 |
| Job Processing    | `job.processing`    | `JobPayload`            | (future)                 |
| Job Completed     | `job.completed`     | `JobPayload`            | (future)                 |
| Job Failed        | `job.failed`        | `JobPayload`            | (future)                 |
| Scheduler Run     | `scheduler.run`     | `SchedulerRunPayload`   | `InstrumentedScheduler`  |
| Outgoing HTTP     | `outgoing.http`     | `OutgoingHttpPayload`   | (future)                 |
| Notification      | `notification`      | `NotificationPayload`   | (future)                 |
| Exception         | `exception`         | `ExceptionPayload`      | `ExceptionCollector`     |
| Log Entry         | `log.entry`         | `LogEntryPayload`       | `LogCollector`           |
| Feature Flag Eval | `feature_flag.eval` | `FeatureFlagPayload`    | `FeatureFlagCollector`   |
| Heartbeat         | `heartbeat`         | (none)                  | (internal)               |

### Event envelope

Every event is wrapped in an `EventEnvelope` containing:

| Field           | Type           | Description                                        |
| --------------- | -------------- | -------------------------------------------------- |
| `eventId`       | `string`       | Hex-encoded 16-byte random identifier              |
| `eventType`     | `EventType`    | Enum value identifying the event kind              |
| `schemaVersion` | `EventVersion` | Payload schema version for forward compatibility   |
| `timestampUs`   | `int`          | Microsecond-precision Unix timestamp               |
| `requestId`     | `?string`      | HTTP request correlation ID                        |
| `traceId`       | `?string`      | Distributed trace ID                               |
| `spanId`        | `?string`      | Current span ID                                    |
| `jobId`         | `?string`      | Scheduler job correlation ID                       |
| `appEnv`        | `string`       | Application environment (local/staging/production) |
| `hostname`      | `string`       | Server hostname                                    |
| `payload`       | `array`        | Serialized payload DTO                             |
| `payloadHash`   | `string`       | SHA-256 hash of the serialized payload JSON        |

### Canonical form

For hash chain computation, each envelope produces a canonical string:

```
eventId|eventType|schemaVersion|timestampUs|traceId|payloadHash
```

Null fields are encoded as empty strings. The pipe delimiter never appears in any field value, so no escaping is needed.

### Payload DTOs

#### HttpRequestPayload

| Field      | Type     | Description         |
| ---------- | -------- | ------------------- |
| `method`   | `string` | HTTP method         |
| `uri`      | `string` | Request URI         |
| `path`     | `string` | Request path        |
| `headers`  | `array`  | Redacted header map |
| `bodySize` | `int`    | Request body size   |

#### HttpResponsePayload

| Field        | Type    | Description            |
| ------------ | ------- | ---------------------- |
| `statusCode` | `int`   | HTTP status code       |
| `headers`    | `array` | Response header map    |
| `bodySize`   | `int`   | Response body size     |
| `durationMs` | `float` | Request duration in ms |

#### DatabaseQueryPayload

| Field            | Type      | Description                                       |
| ---------------- | --------- | ------------------------------------------------- |
| `sql`            | `string`  | Normalized SQL (literals stripped)                |
| `sqlFingerprint` | `string`  | SHA-256 hash of normalized SQL                    |
| `sqlRaw`         | `?string` | Raw SQL (only in local mode with `store_raw_sql`) |
| `connection`     | `string`  | Connection name                                   |
| `durationMs`     | `float`   | Query duration in ms                              |
| `rowCount`       | `?int`    | Number of affected/returned rows                  |

SQL normalization strips numeric and string literals, collapses whitespace, and lowercases keywords. The `sqlFingerprint` groups equivalent queries for performance analysis. Raw SQL is only stored in local development with `store_raw_sql: true` explicitly set.

#### ExceptionPayload

| Field         | Type     | Description                       |
| ------------- | -------- | --------------------------------- |
| `class`       | `string` | Exception class name              |
| `message`     | `string` | Exception message (redacted)      |
| `code`        | `int`    | Exception code                    |
| `file`        | `string` | File where exception was thrown   |
| `line`        | `int`    | Line number                       |
| `fingerprint` | `string` | Error fingerprint for grouping    |
| `previous`    | `?array` | Previous exception (causal chain) |

#### LogEntryPayload

| Field     | Type     | Description                  |
| --------- | -------- | ---------------------------- |
| `level`   | `string` | Log level (emergency..debug) |
| `channel` | `string` | Log channel                  |
| `message` | `string` | Log message (redacted)       |
| `context` | `array`  | Redacted context data        |

#### SchedulerRunPayload

| Field        | Type     | Description                   |
| ------------ | -------- | ----------------------------- |
| `jobName`    | `string` | Scheduled job name            |
| `durationMs` | `float`  | Job duration in ms            |
| `outcome`    | `string` | Job outcome (success/failure) |
| `missed`     | `bool`   | Whether the job was overdue   |

#### FeatureFlagPayload

| Field    | Type     | Description          |
| -------- | -------- | -------------------- |
| `flag`   | `string` | Feature flag name    |
| `value`  | `mixed`  | Evaluated flag value |
| `reason` | `string` | Evaluation reason    |

#### JobPayload

| Field        | Type      | Description               |
| ------------ | --------- | ------------------------- |
| `jobClass`   | `string`  | Job class name            |
| `jobId`      | `string`  | Job identifier            |
| `queue`      | `string`  | Queue name                |
| `durationMs` | `?float`  | Duration (if completed)   |
| `error`      | `?string` | Error message (if failed) |

#### CacheOperationPayload

| Field        | Type     | Description                      |
| ------------ | -------- | -------------------------------- |
| `operation`  | `string` | Operation (get/set/delete/flush) |
| `key`        | `string` | Cache key                        |
| `hit`        | `?bool`  | Cache hit/miss                   |
| `durationMs` | `float`  | Operation duration               |

#### OutgoingHttpPayload

| Field        | Type     | Description           |
| ------------ | -------- | --------------------- |
| `method`     | `string` | HTTP method           |
| `url`        | `string` | Target URL (redacted) |
| `statusCode` | `?int`   | Response status code  |
| `durationMs` | `float`  | Request duration      |

#### NotificationPayload

| Field     | Type     | Description          |
| --------- | -------- | -------------------- |
| `channel` | `string` | Notification channel |
| `type`    | `string` | Notification type    |
| `status`  | `string` | Delivery status      |

#### TenancyPayload

| Field        | Type      | Description        |
| ------------ | --------- | ------------------ |
| `tenantId`   | `string`  | Tenant identifier  |
| `action`     | `string`  | Tenancy action     |
| `databaseId` | `?string` | Tenant database ID |

### Redaction

All payloads pass through the `RedactionPipeline` before storage. The `DefaultRedactionPolicy` scrubs:

- Bearer tokens and API keys in headers
- Password fields in request bodies
- Database connection strings (DSNs)
- Session tokens and cookies
- Inline SQL literals (via `SqlNormalizer`)

Redacted values are replaced with `[REDACTED]`. Redaction is applied before hashing, so `payloadHash` always reflects the redacted content.

### Correlation

Events are correlated using IDs from `CorrelationContext`:

- `requestId`: Groups all events from a single HTTP request
- `traceId`: Links to distributed tracing spans
- `jobId`: Groups all events from a single scheduler job

When a job runs within an HTTP request, events carry both `requestId` and `jobId`. The timeline view reconstructs the full execution flow from these correlation IDs.

Tenant identity is resolved at ingest time via `TenantContext::tryGet()` and stored as a hashed value (`tenant_hash`), not as part of the correlation context.
