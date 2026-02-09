# Observability

Pulsar provides a built-in observability suite: metrics collection, distributed tracing, and error tracking. All components are homegrown with no external service dependencies.

## Metrics

### Metric Types

Three metric types are available via `MetricType` enum:

| Type      | Class                                    | Description                                     |
| --------- | ---------------------------------------- | ----------------------------------------------- |
| Counter   | `Pulsar\Observability\Metrics\Counter`   | Monotonic incrementing value                    |
| Gauge     | `Pulsar\Observability\Metrics\Gauge`     | Bidirectional value (set, increment, decrement) |
| Histogram | `Pulsar\Observability\Metrics\Histogram` | Bucket-based distribution tracking              |

### MetricRegistry

Central store for all metrics. Provides create-or-return semantics — requesting the same name and type returns the existing instance. Requesting the same name with a different type throws `MetricsException`.

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

### OpenMetrics Exporter

`OpenMetricsExporter` renders the entire registry as OpenMetrics text exposition format 0.0.4:

```php
$exporter = new OpenMetricsExporter($registry);
$text = $exporter->export();
```

Output includes `# HELP`, `# TYPE`, sample lines with labels, and histogram `_bucket`/`_sum`/`_count` series. The exporter is registered as a route at the configured endpoint (default `/metrics`) when the metrics exporter is enabled.

## Tracing

### Core Concepts

Distributed tracing follows the W3C Trace Context specification:

- **TraceId** — 128-bit hex identifier (32 chars) for the entire trace
- **SpanId** — 64-bit hex identifier (16 chars) for a single operation
- **TraceContext** — Immutable value object holding traceId, spanId, and trace flags
- **Span** — Mutable lifecycle object representing a timed operation

### Creating Spans

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

### Trace Context Propagation

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

## Error Tracking

### Error Fingerprinting

Errors are grouped by a deterministic SHA-256 fingerprint derived from:

```
{exception class}|{message}|{file}|{line}
```

This produces stable groups — the same error at the same location always maps to the same fingerprint.

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

### Sensitive Data Scrubbing

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

- `pulsar_http_requests_total` — Counter with labels: method, path, status
- `pulsar_http_request_duration_seconds` — Histogram of request durations
- `pulsar_http_errors_total` — Counter for 5xx responses with labels: method, path

### Middleware Ordering

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

- `MetricsConfig` — `enabled`, `exporterEnabled`, `exporterEndpoint`
- `TracingConfig` — `enabled`, `samplingRate`
- `ErrorTrackingConfig` — `enabled`, `maxGroups`, `maxRecentEventsPerGroup`, `sensitiveFields`

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

## Kernel Boot Pipeline

The observability subsystems are initialized during kernel boot in this order:

```
Config -> Logger -> Tracer -> Metrics -> ErrorTracker -> ExceptionHandler -> DiagnosticsRoute -> Extensions
```

This ensures tracing is available before metrics (so metrics middleware can access trace context), and error tracking is available before the exception handler is constructed.
