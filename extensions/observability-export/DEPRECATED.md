# DEPRECATED

This extension has been superseded by `extensions/observability/`.

All classes from `Pulsar\Extension\ObservabilityExport\*` are now available under
`Pulsar\Extension\Observability\Export\JsonLines\*` with the following namespace mapping:

| Old namespace                    | New namespace                                                     |
| -------------------------------- | ----------------------------------------------------------------- |
| `ObservabilityExport\Span\*`     | `Observability\Export\JsonLines\*` (Span interfaces/exporters)    |
| `ObservabilityExport\Metrics\*`  | `Observability\Export\JsonLines\*` (Metrics interfaces/exporters) |
| `ObservabilityExport\Error\*`    | `Observability\Export\JsonLines\*` (Error interfaces/exporters)   |
| `ObservabilityExport\Schema\*`   | `Observability\Export\JsonLines\Schema\*`                         |
| `ObservabilityExport\Internal\*` | `Observability\Export\JsonLines\*` (JsonLinesFileWriter)          |

This directory can be safely deleted once all references have been migrated.
