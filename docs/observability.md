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

### Metrics exporter

`OpenMetricsExporter` renders the entire registry as **Prometheus text
exposition format 0.0.4** - the format Prometheus itself scrapes, and the one
the endpoint advertises: `Content-Type: text/plain; version=0.0.4; charset=utf-8`.

```php
$exporter = new OpenMetricsExporter($registry);
$text = $exporter->export();
```

The class name says OpenMetrics; the output is not OpenMetrics. Version 0.0.4
is a Prometheus format version, not an OpenMetrics one (OpenMetrics is at
1.0.0), and the exporter emits no `# EOF` terminator, which OpenMetrics
requires. The name is kept because it is public surface and the RC phase
prefers additive change; read it as "the metrics exporter", and configure
scrapers for the Prometheus text format. A client that negotiates
`application/openmetrics-text` will not get it.

Output includes `# HELP`, `# TYPE`, sample lines with labels, and histogram
`_bucket`/`_sum`/`_count` series. A counter, gauge or histogram with no recorded
value still emits a zero-valued line, so a scrape never silently drops a
registered metric.

The exporter is registered as a route at the configured endpoint (default
`/metrics`) when the metrics exporter is enabled. That route is operator-only:
it answers `401` with a `WWW-Authenticate: Bearer` challenge unless the request
carries the `PULSAR_DIAGNOSTICS_TOKEN` bearer token, so the scraper has to be
configured with it.

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

### ModelBindingMiddleware

Route model binding reports what it refused, and reports it here rather than in the
tamper-evident audit chain:

- `pulsar_model_binding_anonymous_denials_total` - Counter with labels: route, reason. Every
  request a bound route refused for having no authenticated caller. These write nothing to the
  audit chain: nothing was accessed, every entry would be the same entry, and an entry per
  request would let an unauthenticated caller decide how far the chain grows. A denial that
  names somebody is still chained in full, with no ceiling.
- `pulsar_model_binding_refusals_total` - Counter with labels: route, status. Every refusal the
  middleware serves, of every status. This is what keeps "a route that cannot be served is
  diagnosed once" an aggregation rather than a suppression: the `error` line stops repeating,
  the occurrences do not stop being counted.

Both label sets are drawn from the route table, never from the request. A `Counter` keys a map
in memory on its labels, so a label the caller chooses is a map the caller sizes.

Both series are registered when the middleware is composed, so they exist at zero before
anything is refused - a dashboard can tell "no anonymous denials" from "not reporting". With
`metrics.enabled` off there is no registry and the counts are lost; `ModelBindingWiring`
declares `MetricRegistry` as an optional binding so that shows up as a degraded feature rather
than as a silence. See [Route model binding](route-model-binding.md).

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

`EventType` is the authority; the wire value is the enum's backing string. Note
that four of them do **not** follow the `{subsystem}.{verb}` shape their names
suggest: database queries are `db.query`, outgoing HTTP is
`outgoing_http.request`, notifications are `notification.sent`.

| Enum case                | Wire value                 | Payload DTO                     | Producer                   |
| ------------------------ | -------------------------- | ------------------------------- | -------------------------- |
| `HttpRequest`            | `http.request`             | `HttpRequestPayload`            | `HttpCollector`            |
| `HttpResponse`           | `http.response`            | `HttpResponsePayload`           | `HttpCollector`            |
| `DatabaseQuery`          | `db.query`                 | `DatabaseQueryPayload`          | `InstrumentedConnection`   |
| `SchedulerRun`           | `scheduler.run`            | `SchedulerRunPayload`           | `InstrumentedScheduler`    |
| `JobQueued`              | `job.queued`               | `JobPayload`                    | `InstrumentedQueueManager` |
| `JobCompleted`           | `job.completed`            | `JobPayload`                    | `InstrumentedWorker`       |
| `JobFailed`              | `job.failed`               | `JobPayload`                    | `InstrumentedWorker`       |
| `Exception`              | `exception`                | `ExceptionPayload`              | `ExceptionCollector`       |
| `LogEntry`               | `log.entry`                | `LogEntryPayload`               | `LogCollector`             |
| `FeatureFlagEval`        | `feature_flag.eval`        | `FeatureFlagPayload`            | `FeatureFlagCollector`     |
| `RuntimeWorkerStart`     | `runtime.worker_start`     | `RuntimeWorkerStartPayload`     | `InstrumentedRuntime`      |
| `RuntimeWorkerRecycle`   | `runtime.worker_recycle`   | `RuntimeWorkerRecyclePayload`   | `InstrumentedRuntime`      |
| `RuntimeRequestComplete` | `runtime.request_complete` | `RuntimeRequestCompletePayload` | `InstrumentedRuntime`      |
| `RuntimeLeakWarning`     | `runtime.leak_warning`     | `RuntimeLeakWarningPayload`     | `InstrumentedRuntime`      |
| `RuntimeSchedulerMetric` | `runtime.scheduler_metric` | `RuntimeSchedulerMetricPayload` | `InstrumentedRuntime`      |
| `BenchmarkProfile`       | `benchmark.profile`        | `BenchmarkProfilePayload`       | `studio:console:bench`     |
| `BenchmarkRun`           | `benchmark.run`            | `BenchmarkRunPayload`           | `studio:console:bench`     |

#### Declared but not emitted

These enum cases and DTOs exist and are tested, and nothing in the framework
constructs them. A dashboard filtering for them will always be empty:

| Enum case           | Wire value              | Payload DTO             | What is missing                                                      |
| ------------------- | ----------------------- | ----------------------- | -------------------------------------------------------------------- |
| `JobProcessing`     | `job.processing`        | `JobPayload`            | A producer; `InstrumentedWorker` emits only `completed` and `failed` |
| `CacheOperation`    | `cache.operation`       | `CacheOperationPayload` | An implementation of `Hook\CacheInstrumentationInterface`            |
| `OutgoingHttp`      | `outgoing_http.request` | `OutgoingHttpPayload`   | An implementation of `Hook\HttpClientInstrumentationInterface`       |
| `Notification`      | `notification.sent`     | `NotificationPayload`   | An implementation of `Hook\NotificationInstrumentationInterface`     |
| `IntegrityCheck`    | `integrity.check`       | `IntegrityCheckPayload` | A producer; the integrity subsystem does not emit Studio events      |
| `SupervisorRecycle` | `supervisor.recycle`    | `SupervisorPayload`     | A producer - and the DTO reports `Heartbeat`, not this               |
| `SupervisorHealing` | `supervisor.healing`    | `SupervisorPayload`     | A producer - and the DTO reports `Heartbeat`, not this               |
| `Heartbeat`         | `heartbeat`             | -                       | A producer; two DTOs report it, neither is ever constructed          |
| -                   | -                       | `TenancyPayload`        | A producer, and an event type: the DTO also reports `Heartbeat`      |

`Heartbeat` itself is emitted by nothing either. `SupervisorPayload` and
`TenancyPayload` both return it from `eventType()`, so if either ever gains a
producer its events will arrive labelled `heartbeat` until that is corrected.

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

The envelope's `payload` is the DTO's `toArray()` output, so the **keys below are
the wire keys** - `snake_case`, not the DTO's camelCase property names. A
consumer indexing `payload['statusCode']` finds nothing; the key is
`status_code`.

#### HttpRequestPayload

| Key                 | Type      | Description                                 |
| ------------------- | --------- | ------------------------------------------- |
| `method`            | `string`  | HTTP method                                 |
| `uri`               | `string`  | Request URI                                 |
| `path`              | `string`  | Request path                                |
| `headers`           | `array`   | Redacted header map                         |
| `client_ip`         | `?string` | Client IP address                           |
| `user_agent`        | `?string` | User agent string                           |
| `content_type`      | `?string` | Request content type                        |
| `content_length`    | `?int`    | Request body size in bytes                  |
| `route_name`        | `?string` | Matched route name                          |
| `query_string`      | `?string` | Raw query string                            |
| `body_preview`      | `?string` | Truncated, redacted request body            |
| `controller_class`  | `?string` | Resolved controller class                   |
| `controller_method` | `?string` | Resolved controller method                  |
| `session`           | `array`   | Redacted session snapshot                   |
| `middleware`        | `array`   | Middleware names the request passed through |
| `route_params`      | `array`   | Matched route parameters                    |

#### HttpResponsePayload

| Key              | Type      | Description             |
| ---------------- | --------- | ----------------------- |
| `status_code`    | `int`     | HTTP status code        |
| `duration_ms`    | `float`   | Request duration in ms  |
| `headers`        | `array`   | Response header map     |
| `content_length` | `?int`    | Response body size      |
| `content_type`   | `?string` | Response content type   |
| `route_name`     | `?string` | Matched route name      |
| `body_preview`   | `?string` | Truncated response body |

#### DatabaseQueryPayload

| Key                | Type      | Description                                 |
| ------------------ | --------- | ------------------------------------------- |
| `sql`              | `string`  | Normalized SQL (literals replaced with `?`) |
| `sql_fingerprint`  | `string`  | SHA-256 hash of normalized SQL              |
| `connection_name`  | `string`  | Connection name                             |
| `duration_ms`      | `float`   | Query duration in ms                        |
| `row_count`        | `?int`    | Number of affected/returned rows            |
| `query_type`       | `string`  | Leading keyword (`SELECT`, `INSERT`, ...)   |
| `sql_raw`          | `?string` | Raw SQL - see below                         |
| `call_site_file`   | `?string` | File that issued the query                  |
| `call_site_line`   | `?int`    | Line that issued the query                  |
| `call_site_class`  | `?string` | Class that issued the query                 |
| `call_site_method` | `?string` | Method that issued the query                |

SQL normalization replaces quoted string and numeric literals with `?`,
collapses whitespace, and lowercases keywords. The `sql_fingerprint` groups
equivalent queries for performance analysis. `sql_raw` is populated only when
`InstrumentedConnection` was constructed with `storeRawSql: true` **and** the
environment mode is `Local` - both conditions, every time; otherwise it is null.

#### ExceptionPayload

| Key                | Type      | Description                     |
| ------------------ | --------- | ------------------------------- |
| `exception_class`  | `string`  | Exception class name            |
| `message`          | `string`  | Exception message (redacted)    |
| `file`             | `string`  | File where exception was thrown |
| `line`             | `int`     | Line number                     |
| `fingerprint`      | `string`  | Error fingerprint for grouping  |
| `stack_trace`      | `array`   | Frames, redacted                |
| `previous_class`   | `?string` | Previous exception class        |
| `previous_message` | `?string` | Previous exception message      |

There is no `code` key. The causal chain is flattened to one level: the previous
exception contributes a class and a message, not a nested payload.

#### LogEntryPayload

| Key       | Type     | Description                  |
| --------- | -------- | ---------------------------- |
| `level`   | `string` | Log level (emergency..debug) |
| `message` | `string` | Log message (redacted)       |
| `channel` | `string` | Log channel                  |
| `context` | `array`  | Redacted context data        |

#### SchedulerRunPayload

| Key             | Type      | Description                     |
| --------------- | --------- | ------------------------------- |
| `job_name`      | `string`  | Scheduled job name              |
| `status`        | `string`  | Job status                      |
| `duration_ms`   | `float`   | Job duration in ms              |
| `error_message` | `?string` | Failure reason, null on success |
| `missed`        | `bool`    | Whether the job was overdue     |

#### FeatureFlagPayload

| Key                  | Type      | Description                       |
| -------------------- | --------- | --------------------------------- |
| `flag_name`          | `string`  | Feature flag name                 |
| `result`             | `bool`    | Evaluated result                  |
| `reason`             | `string`  | Evaluation reason                 |
| `context_identifier` | `?string` | Evaluation context, when supplied |

`result` is a boolean, not an arbitrary value: this payload records flag
evaluation, and a flag evaluates on or off.

#### JobPayload

| Key             | Type      | Description                                                           |
| --------------- | --------- | --------------------------------------------------------------------- |
| `job_class`     | `string`  | Job class name                                                        |
| `status`        | `string`  | `queued`, `processing`, `completed` or anything else (read as failed) |
| `queue`         | `?string` | Queue name                                                            |
| `duration_ms`   | `?float`  | Duration (if completed)                                               |
| `error_message` | `?string` | Error message (if failed)                                             |
| `attempts`      | `int`     | Attempt number, default 1                                             |
| `connection`    | `?string` | Queue connection name                                                 |

`status` is what selects the event type, through
`JobPayload::eventType()`. There is no job-id key: correlation runs through the
envelope's `jobId`.

`InstrumentedWorker` emits `completed` and `failed` only. Nothing emits
`processing`, so `job.processing` never appears on the wire despite the enum
case existing. `InstrumentedWorker` also has no access to the job class at emit
time and writes the literal `unknown` into `job_class`; the class name is
available on the `job.queued` event from `InstrumentedQueueManager`, joined by
the envelope's correlation ids.

#### Payloads with no producer

The DTOs below are complete and tested but nothing in the framework constructs
them - see [Declared but not emitted](#declared-but-not-emitted). They are
documented so an application wiring its own instrumentation hook knows the
shape to fill.

`CacheOperationPayload`: `operation`, `key`, `hit`, `duration_ms`, `store`, `ttl`.

`OutgoingHttpPayload`: `method`, `url`, `status_code`, `duration_ms`, `error_message`.

`NotificationPayload`: `channel`, `recipient`, `status`, `duration_ms`, `error_message`.

`IntegrityCheckPayload`: `passed`, `verified`, `modified`, `missing`, `added`, `checked_at`.

`SupervisorPayload`: `type`, `action`, `success`, `details`, `performed_at`.

`TenancyPayload`: `tenant_hash`, `resolver_strategy`, `resolved`. The tenant is
hashed, not named - a tenant identifier in a telemetry stream is a disclosure.

#### Runtime payloads

Emitted by `InstrumentedRuntime` under the persistent runtime only. A
request-per-process deployment produces none of them.

| Payload                         | Keys                                                                                     |
| ------------------------------- | ---------------------------------------------------------------------------------------- |
| `RuntimeWorkerStartPayload`     | `host`, `port`, `fiber_concurrency`, `max_requests`, `memory_threshold_mb`, `started_at` |
| `RuntimeWorkerRecyclePayload`   | `reason`, `request_count`, `memory_usage_mb`, `uptime_seconds`                           |
| `RuntimeRequestCompletePayload` | `method`, `path`, `status_code`, `duration_ms`, `memory_delta_bytes`                     |
| `RuntimeLeakWarningPayload`     | `warnings`, `memory_delta_bytes`, `request_number`                                       |
| `RuntimeSchedulerMetricPayload` | `active_fibers`, `total_spawned`, `total_completed`, `uptime_seconds`                    |

#### Benchmark payloads

Emitted by `studio:console:bench`, never during request handling.

| Payload                   | Keys                                                                                                                                                                                                                                         |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `BenchmarkRunPayload`     | `run_id`, `php_version`, `php_sapi`, `os_platform`, `os_arch`, `profile_count`, `success_count`, `failure_count`, `skipped_count`, `total_duration_ms`, `profile_names`                                                                      |
| `BenchmarkProfilePayload` | `run_id`, `profile_name`, `profile_description`, `boot_us`, `warm_boot_us`, `p50_us`, `p95_us`, `rps`, `peak_rss_kb`, `memory_usage_kb`, `opcache_memory_kb`, `iterations`, `jit_enabled`, `jit_mode`, `preload_enabled`, `optimize_enabled` |

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
