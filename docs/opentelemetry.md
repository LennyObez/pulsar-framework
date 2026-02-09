# OpenTelemetry

Pulsar's OpenTelemetry extension exports traces, metrics, and logs to any OTLP-compatible collector. It bridges Pulsar's built-in observability APIs to the OpenTelemetry Protocol (OTLP), giving you full control over sampling, cardinality, and transport without pulling in the OpenTelemetry PHP SDK.

## Quick start

### 1. Enable the extension

Add the OpenTelemetry extension to your `pulsar.json`:

```json
{
  "extensions": {
    "opentelemetry": {
      "enabled": true
    }
  }
}
```

### 2. Configure the collector endpoint

In your `.env`:

```bash
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
OTEL_SERVICE_NAME=my-app
```

Or in `config/opentelemetry.php`:

```php
return [
    'enabled' => true,
    'endpoint' => 'http://localhost:4318',
    'service_name' => 'my-app',
    'service_version' => '1.0.0',
];
```

### 3. Verify

Start your collector and run your application. Traces, metrics, and logs will flow to the configured endpoint automatically.

## Configuration reference

Configuration is structured as a root `OpenTelemetryConfig` with nested sub-configs for each concern. All fields have sensible defaults.

### Root configuration

| Field               | Type           | Default                       | Description                                                |
| ------------------- | -------------- | ----------------------------- | ---------------------------------------------------------- |
| `enabled`           | `bool`         | `false`                       | Master kill-switch for all OTLP export                     |
| `endpoint`          | `string`       | `http://localhost:4318`       | Base OTLP endpoint URL                                     |
| `protocol`          | `OtlpProtocol` | `http/protobuf`               | Transport protocol: `http/protobuf` or `grpc`              |
| `timeout_ms`        | `int`          | `5000`                        | Export timeout in milliseconds                             |
| `headers`           | `array`        | `[]`                          | Extra HTTP headers sent with every request                 |
| `service_name`      | `string`       | `''`                          | `service.name` resource attribute                          |
| `service_version`   | `string`       | `''`                          | `service.version` resource attribute                       |
| `service_namespace` | `string`       | `''`                          | `service.namespace` resource attribute                     |
| `propagators`       | `list<string>` | `['tracecontext', 'baggage']` | Context propagation formats                                |
| `dual_export`       | `bool`         | `false`                       | Also export to Studio (in-memory collector) simultaneously |

### Traces configuration (`traces.*`)

| Field                 | Type                | Default | Description                                           |
| --------------------- | ------------------- | ------- | ----------------------------------------------------- |
| `enabled`             | `bool`              | `true`  | Whether trace export is enabled                       |
| `endpoint`            | `string`            | `''`    | Endpoint override (empty = use root endpoint)         |
| `attribute_allowlist` | `array`             | `[]`    | Scope-to-allowed-keys mapping for attribute filtering |
| `db_statement_export` | `DbStatementExport` | `none`  | How to export `db.statement`: `none`, `hash`, `full`  |

### Metrics configuration (`metrics.*`)

| Field                 | Type     | Default | Description                                   |
| --------------------- | -------- | ------- | --------------------------------------------- |
| `enabled`             | `bool`   | `true`  | Whether metrics export is enabled             |
| `endpoint`            | `string` | `''`    | Endpoint override (empty = use root endpoint) |
| `collect_interval_ms` | `int`    | `60000` | Collection interval in milliseconds           |

### Logs configuration (`logs.*`)

| Field       | Type     | Default     | Description                                   |
| ----------- | -------- | ----------- | --------------------------------------------- |
| `enabled`   | `bool`   | `true`      | Whether log export is enabled                 |
| `endpoint`  | `string` | `''`        | Endpoint override (empty = use root endpoint) |
| `min_level` | `string` | `'warning'` | Minimum log level to export                   |

### Sampler configuration (`sampler.*`)

| Field             | Type          | Default        | Description                                         |
| ----------------- | ------------- | -------------- | --------------------------------------------------- |
| `type`            | `SamplerType` | `parent_based` | Sampling strategy (see Sampling Strategies below)   |
| `probability`     | `float`       | `1.0`          | Probability for the `probability` sampler (0.0-1.0) |
| `rate_per_second` | `float`       | `100.0`        | Max traces/sec for the `rate_limited` sampler       |

### Batch configuration (`batch.*`)

| Field            | Type  | Default | Description                                 |
| ---------------- | ----- | ------- | ------------------------------------------- |
| `max_batch_size` | `int` | `512`   | Items per export batch                      |
| `max_queue_size` | `int` | `2048`  | Queue capacity before dropping oldest items |

### Cardinality configuration (`cardinality.*`)

| Field                | Type   | Default | Description                                       |
| -------------------- | ------ | ------- | ------------------------------------------------- |
| `max_attribute_keys` | `int`  | `1000`  | Maximum unknown attribute keys to track per scope |
| `max_metric_series`  | `int`  | `2000`  | Maximum unique label combinations per metric name |
| `normalize_urls`     | `bool` | `true`  | Normalize URL paths to reduce cardinality         |

### Full configuration example

```php
// config/opentelemetry.php
return [
    'enabled' => true,
    'endpoint' => 'http://collector.internal:4318',
    'protocol' => 'http/protobuf',
    'timeout_ms' => 5000,
    'headers' => ['Authorization' => 'Bearer my-token'],
    'service_name' => 'payment-service',
    'service_version' => '2.1.0',
    'service_namespace' => 'payments',
    'dual_export' => true,
    'propagators' => ['tracecontext', 'baggage'],

    'traces' => [
        'enabled' => true,
        'attribute_allowlist' => [
            'http.server' => ['http.method', 'http.route', 'http.status_code'],
            'db.client' => ['db.system', 'db.operation', 'db.name'],
        ],
        'db_statement_export' => 'hash',
    ],

    'metrics' => [
        'enabled' => true,
        'collect_interval_ms' => 30000,
    ],

    'logs' => [
        'enabled' => true,
        'min_level' => 'warning',
    ],

    'sampler' => [
        'type' => 'probability',
        'probability' => 0.1,
        'rate_per_second' => 100.0,
    ],

    'batch' => [
        'max_batch_size' => 512,
        'max_queue_size' => 2048,
    ],

    'cardinality' => [
        'max_attribute_keys' => 1000,
        'max_metric_series' => 2000,
        'normalize_urls' => true,
    ],
];
```

## Environment variables

Standard OTel environment variables override config file values. This follows the OTel SDK specification.

| Variable                      | Config Override       | Example                           |
| ----------------------------- | --------------------- | --------------------------------- |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `endpoint`            | `http://collector:4318`           |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `protocol`            | `http/protobuf` or `grpc`         |
| `OTEL_SERVICE_NAME`           | `service_name`        | `my-app`                          |
| `OTEL_TRACES_SAMPLER`         | `sampler.type`        | `always_on`, `traceidratio`, etc. |
| `OTEL_TRACES_SAMPLER_ARG`     | `sampler.probability` | `0.1`                             |
| `OTEL_EXPORTER_OTLP_HEADERS`  | `headers`             | `key1=val1,key2=val2`             |

The sampler environment variable maps standard OTel SDK names to Pulsar sampler types:

| OTel SDK Name              | Pulsar SamplerType |
| -------------------------- | ------------------ |
| `always_on`                | `always`           |
| `always_off`               | `never`            |
| `traceidratio`             | `probability`      |
| `parentbased_always_on`    | `parent_based`     |
| `parentbased_traceidratio` | `parent_based`     |
| `parentbased_always_off`   | `never`            |

## Collector deployment patterns

### Sidecar (recommended for kubernetes)

Each pod runs a collector sidecar container. Lowest latency, strongest isolation. Best for compliance environments where telemetry must not leave the pod before processing.

```
[Pulsar App] --localhost:4318--> [OTel Collector Sidecar] --> [Backend]
```

**Pros**: No network hop, per-pod isolation, easy to configure per-service.
**Cons**: Higher resource usage (one collector per pod).

### Gateway

A shared collector cluster receives telemetry from all application instances.

```
[App 1] --\
[App 2] ---+--> [OTel Collector Gateway] --> [Backend]
[App 3] --/
```

**Pros**: Lower resource overhead, centralized configuration.
**Cons**: Network hop, single point of failure without redundancy.

### Agent (DaemonSet)

One collector per node. Balances isolation and resource efficiency.

```
[App Pod A] --\
                +--> [OTel Collector Agent (Node)] --> [Backend]
[App Pod B] --/
```

**Pros**: Good balance of isolation and efficiency.
**Cons**: Shared between pods on the same node.

## Sample collector configuration

A minimal `otel-collector-config.yaml` that fans out to Jaeger, Zipkin, and Datadog:

```yaml
receivers:
  otlp:
    protocols:
      grpc:
        endpoint: 0.0.0.0:4317
      http:
        endpoint: 0.0.0.0:4318

processors:
  batch:
    timeout: 5s
    send_batch_size: 512

exporters:
  otlp/jaeger:
    endpoint: jaeger:4317
    tls:
      insecure: true

  zipkin:
    endpoint: http://zipkin:9411/api/v2/spans

  datadog:
    api:
      key: ${DD_API_KEY}
      site: datadoghq.com

  prometheus:
    endpoint: 0.0.0.0:8889

  otlp/logs:
    endpoint: loki:4317
    tls:
      insecure: true

service:
  pipelines:
    traces:
      receivers: [otlp]
      processors: [batch]
      exporters: [otlp/jaeger, zipkin, datadog]
    metrics:
      receivers: [otlp]
      processors: [batch]
      exporters: [prometheus]
    logs:
      receivers: [otlp]
      processors: [batch]
      exporters: [otlp/logs]
```

## Sampling strategies

Sampling controls which traces are recorded and exported. The sampler is evaluated once per root span - child spans inherit the parent's decision.

| Strategy     | Config Value   | When to Use                                           |
| ------------ | -------------- | ----------------------------------------------------- |
| Always       | `always`       | Development and staging. Record every trace.          |
| Never        | `never`        | Disable tracing without removing code.                |
| Probability  | `probability`  | Production default. Record a fixed percentage.        |
| Rate-limited | `rate_limited` | Bursty workloads. Cap at N traces/sec.                |
| Parent-based | `parent_based` | Distributed systems. Respect upstream sampling flags. |

### When to use each

**Always**: Use during development and in staging environments where you want full visibility. Not recommended for production due to volume.

**Probability**: The most common production choice. Start with 10% (`probability: 0.1`) and adjust based on volume and budget. Every trace has an equal chance of being sampled.

```php
'sampler' => [
    'type' => 'probability',
    'probability' => 0.1,
],
```

**Rate-limited**: Caps throughput regardless of traffic volume. Useful when you need predictable export costs:

```php
'sampler' => [
    'type' => 'rate_limited',
    'rate_per_second' => 50.0,
],
```

**Parent-based**: The default. Respects the `sampled` flag from incoming W3C `traceparent` headers. Sampled parents produce sampled children; unsampled parents produce unsampled children. Root spans (created without an incoming trace context) default to sampled since `TraceContext::create()` sets the sampled flag. Use this in microservice architectures where sampling decisions should be consistent across the call chain.

## Cardinality protection

High-cardinality attributes (user IDs, request paths with UUIDs, session tokens) can cause unbounded memory growth in both the application and the collector. Pulsar provides three layers of defense.

### Attribute allowlist

Restricts which attribute keys are forwarded per instrumentation scope. Attributes not in the allowlist are dropped before serialization:

```php
'traces' => [
    'attribute_allowlist' => [
        'http.server' => ['http.method', 'http.route', 'http.status_code'],
        'db.client' => ['db.system', 'db.operation', 'db.name'],
    ],
],
```

When a scope has no allowlist entry, all attributes pass through. Unknown keys are logged once at debug level via the `OverflowTracker` (bounded to `max_attribute_keys` entries to prevent the tracker itself from growing unbounded).

### Series limiter

Caps the number of unique label combinations per metric name:

```php
'cardinality' => [
    'max_metric_series' => 2000,
],
```

When a metric exceeds its series limit, new label combinations are collapsed into a single `__overflow=true` bucket. The collector receives bounded data regardless of input diversity.

### URL normalization

When `normalize_urls` is `true` (the default), URL path segments that look like IDs (UUIDs, numeric IDs) are replaced with placeholders before being used as span or metric attributes. This prevents `/users/123` and `/users/456` from creating separate series.

## Database instrumentation

Database spans follow a privacy-by-default model. The `db_statement_export` setting controls how SQL statements appear in span attributes.

| Mode   | `db.statement` Attribute Value                   | Use Case                            |
| ------ | ------------------------------------------------ | ----------------------------------- |
| `none` | Not exported (default)                           | Regulated environments (HIPAA, PCI) |
| `hash` | One-way SHA-256 hash of the statement            | Debugging without PII exposure      |
| `full` | Full SQL text (force-disabled in regulated mode) | Development and staging only        |

```php
'traces' => [
    'db_statement_export' => 'hash',
],
```

When `regulated_mode` is active (derived from application environment), the `full` mode is automatically downgraded to `hash` to prevent accidental PII leakage through SQL query parameters.

Database spans always include safe attributes (`db.system`, `db.name`, `db.operation`) regardless of the statement export setting.

## Queue instrumentation

Trace context is automatically propagated through queued job payloads. When a job is dispatched, the current `traceparent` and `baggage` headers are serialized into the job's metadata. When the worker picks up the job, the trace context is restored so the worker's spans appear as children of the dispatching span.

This works with Pulsar's built-in queue system. The propagation format follows W3C Trace Context and Baggage specifications, using the configured propagators (`['tracecontext', 'baggage']` by default).

If the parent span was not sampled, the worker span inherits the unsampled flag and no telemetry is exported for that job execution (when using the `parent_based` sampler).

## Custom spans

Pulsar's tracing API works regardless of whether OTLP export is enabled. Create spans in your application code and they flow to Studio and/or the OTLP collector automatically.

```php
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;

// Create a root span
$context = TraceContext::create();
$span = new Span('payment.process', $context);
$span->setAttribute('payment.amount', 99.99);
$span->setAttribute('payment.currency', 'USD');

try {
    // ... your logic ...
    $span->status = SpanStatus::Ok;
} catch (\Throwable $e) {
    $span->status = SpanStatus::Error;
    $span->setAttribute('error.message', $e->getMessage());
} finally {
    $span->end();
}
```

Child spans inherit the parent's trace ID:

```php
$childContext = $context->createChild();
$childSpan = new Span('payment.gateway.call', $childContext, parentSpanId: $context->spanId);
$childSpan->setAttribute('gateway.provider', 'stripe');
// ...
$childSpan->end();
```

When OTLP export is enabled, the `SpanProcessorInterface` bridge converts completed `Span` objects into `OtlpSpan` DTOs, which flow through the batch exporter and out via the configured transport. Your application code does not need to know about OTLP - just create spans and call `end()`.

## Dual export (Studio + collector)

When `dual_export` is `true`, Pulsar sends telemetry to both Studio (the built-in in-memory dashboard) and the OTLP collector simultaneously. Studio uses an `InMemorySpanCollector` for real-time debugging; the OTLP path handles durable export to external backends.

```php
return [
    'enabled' => true,
    'dual_export' => true,
];
```

Set `dual_export` to `false` if you want OTLP-only export (e.g., in production where Studio is disabled).

When `enabled` is `false` and Studio is active, spans still flow to Studio's in-memory collector. The OTLP path is fully inactive - no serialization, no network calls, no allocations.

## Performance

The extension is designed for near-zero overhead on the hot path.

### Disabled mode

When `enabled` is `false`, the extension registers `NoopSpanProcessor` and `NoopLogSink` implementations. These are empty method bodies with no allocations, no conditionals, and no logging. The overhead is a single virtual method dispatch per span/log - effectively zero.

### Enabled mode

- **Boolean guard**: The `enabled` flag is checked once during bootstrap. When disabled, no bridge or exporter objects are instantiated.
- **Batched export**: Items queue in memory and flush in configurable batches (default 512). No per-span network calls.
- **Queue cap**: Bounded at `max_queue_size` (default 2048). Overflow drops oldest items rather than blocking the application.
- **Serialization**: Zero-dependency protobuf encoder using `ProtobufWriter`. No reflection, no external library. Manual wire-format encoding is faster than a generic protobuf library.
- **Transport timeouts**: Configurable per-request timeout (default 5s). Failed exports are logged but never block application flow or throw exceptions.
- **Sampling short-circuit**: When a trace is not sampled, the bridge skips serialization entirely. Unsampled spans are discarded before reaching the batch queue.

### Overhead expectations

| Scenario                | Expected Overhead                                        |
| ----------------------- | -------------------------------------------------------- |
| Extension disabled      | ~0 (no-op processor, no allocations)                     |
| Enabled, 100% sampling  | < 1ms per request                                        |
| Enabled, 10% sampling   | < 0.1ms per request (unsampled spans skip serialization) |
| Batch flush (512 spans) | ~2-5ms (single HTTP POST)                                |
| gRPC transport          | ~1-3ms per batch (lower framing overhead)                |

Actual overhead depends on span count, attribute volume, and collector latency. Profile with `OTEL_EXPORTER_OTLP_TIMEOUT` set to a low value to catch slow collectors early.
