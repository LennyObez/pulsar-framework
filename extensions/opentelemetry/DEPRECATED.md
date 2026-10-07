# DEPRECATED

This extension has been superseded by `extensions/observability/`.

All classes from `Pulsar\Extension\OpenTelemetry\*` are now available under
`Pulsar\Extension\Observability\*` with the following namespace mapping:

| Old namespace                        | New namespace                             |
| ------------------------------------ | ----------------------------------------- |
| `OpenTelemetry\Bridge\*`             | `Observability\Tracing\Bridge\*`          |
| `OpenTelemetry\Sampling\*`           | `Observability\Tracing\Sampling\*`        |
| `OpenTelemetry\Cardinality\*`        | `Observability\Tracing\Cardinality\*`     |
| `OpenTelemetry\Instrumentation\*`    | `Observability\Tracing\Instrumentation\*` |
| `OpenTelemetry\Noop\*`               | `Observability\Tracing\Noop\*`            |
| `OpenTelemetry\Propagation\*`        | `Observability\Tracing\Propagation\*`     |
| `OpenTelemetry\Config\*`             | `Observability\Config\*`                  |
| `OpenTelemetry\Internal\Export\*`    | `Observability\Export\Otlp\*`             |
| `OpenTelemetry\Internal\Protobuf\*`  | `Observability\Export\Otlp\Protobuf\*`    |
| `OpenTelemetry\Internal\Transport\*` | `Observability\Export\Otlp\Transport\*`   |
| `OpenTelemetry\Internal\Exception\*` | `Observability\Internal\Exception\*`      |

This directory can be safely deleted once all references have been migrated.
