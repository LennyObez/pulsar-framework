# ADR-0007: In-House Observability Stack with No Vendor Dependencies

## Status

Accepted

## Context

Observability in PHP typically involves third-party services: Prometheus for metrics, Jaeger/Zipkin for tracing, Sentry for error tracking, and ELK/Datadog for logging aggregation. These are excellent products, but coupling a framework to specific vendor APIs creates problems:

- **Vendor lock-in.** Framework classes named after products (e.g., `PrometheusExporter`, `SentryReporter`) cannot be swapped without code changes across the codebase.
- **Dependency weight.** Client libraries for observability services add significant dependency trees (gRPC, Protobuf, HTTP clients).
- **Deployment requirements.** Running the framework locally or in air-gapped environments requires the same external services as production, or stub implementations.
- **Configuration complexity.** Each service has its own configuration format, authentication, and endpoint management.

Pulsar targets regulated domains where data sovereignty, air-gapped deployments, and minimal external dependencies are common requirements.

## Decision

Build a complete first-party observability suite with no external vendor dependencies. Vendor product names must not appear in source code, class names, or configuration keys.

### Components

- **Structured logging** (`Pulsar\Observability\Log\Logger`) — PSR-3-compatible logger with structured context, configurable channels and severity levels.
- **Metrics collection** (`Pulsar\Observability\Metrics\MetricRegistry`) — Counter, Gauge, and Histogram metric types with dimensional labels via `LabelSet`. Create-or-return semantics prevent duplicate metrics.
- **Distributed tracing** (`Pulsar\Observability\Tracing\`) — Spans with context propagation, parent-child relationships, and timing.
- **Error tracking** — First-party error grouping and reporting with environment-aware rendering (development vs. production).
- **Audit logging** (`Pulsar\Security\Audit\AuditLogger`) — Separate from general logging, with HMAC-chained integrity (see ADR-0008).
- **Studio dashboard** — Built-in developer console for viewing events, traces, and metrics during development.

### Export strategy

The framework collects and stores observability data using its own APIs. Export to external systems is handled by optional, standards-based exporters (e.g., OpenMetrics exposition format for metrics). Exporters are extensions — they are not part of the core observability API.

## Consequences

### Positive

- **Zero vendor coupling.** Switching from one metrics backend to another requires changing an exporter extension, not framework code.
- **Works offline.** Local development, CI, and air-gapped deployments have full observability without external services.
- **Consistent API.** One logging interface, one metrics registry, one tracing API. No per-vendor configuration.
- **Standards-based export.** OpenMetrics and other standard formats provide interoperability without vendor-specific code.

### Negative

- **Missing advanced features.** Purpose-built services (Prometheus, Datadog, Sentry) offer alerting, dashboards, anomaly detection, and long-term storage that Pulsar does not replicate.
- **Storage limitations.** In-process metrics and traces are lost on process restart unless exported. Long-term retention requires external infrastructure.
- **Ecosystem friction.** The PHP observability ecosystem is oriented around OpenTelemetry and vendor SDKs. Pulsar's homegrown approach does not participate in that ecosystem directly.

### Neutral

- **OpenTelemetry bridge.** A future extension could bridge Pulsar's tracing and metrics APIs to OpenTelemetry's SDK. This is not planned for 1.0.0 but is architecturally possible.
