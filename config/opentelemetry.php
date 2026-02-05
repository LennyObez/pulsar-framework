<?php

declare(strict_types=1);

/**
 * OpenTelemetry Extension Configuration
 *
 * OTLP export for traces, metrics, and logs to an OpenTelemetry Collector.
 * Environment variables follow the OTel SDK specification and override file values.
 *
 * @package Pulsar\Extension\OpenTelemetry
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Master Kill-Switch
    |--------------------------------------------------------------------------
    | Set to true to enable OTLP telemetry export. Disabled by default so
    | installations without a collector do not produce connection errors.
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | OTLP Endpoint
    |--------------------------------------------------------------------------
    | Base URL of the OpenTelemetry Collector receiver.
    | Override: OTEL_EXPORTER_OTLP_ENDPOINT
    */
    'endpoint' => 'http://localhost:4318',

    /*
    |--------------------------------------------------------------------------
    | Transport Protocol
    |--------------------------------------------------------------------------
    | Supported: "http/protobuf", "grpc"
    | Override: OTEL_EXPORTER_OTLP_PROTOCOL
    */
    'protocol' => 'http/protobuf',

    /*
    |--------------------------------------------------------------------------
    | Export Timeout (ms)
    |--------------------------------------------------------------------------
    */
    'timeout_ms' => 5000,

    /*
    |--------------------------------------------------------------------------
    | Extra HTTP Headers
    |--------------------------------------------------------------------------
    | Key-value pairs sent with every OTLP request (e.g., auth tokens).
    | Override: OTEL_EXPORTER_OTLP_HEADERS (comma-separated key=value)
    */
    'headers' => [],

    /*
    |--------------------------------------------------------------------------
    | Service Identity
    |--------------------------------------------------------------------------
    | Mapped to resource attributes: service.name, service.version,
    | service.namespace.
    | Override (name only): OTEL_SERVICE_NAME
    */
    'service_name' => '',
    'service_version' => '',
    'service_namespace' => '',

    /*
    |--------------------------------------------------------------------------
    | Traces
    |--------------------------------------------------------------------------
    */
    'traces' => [
        'enabled' => true,
        'endpoint' => '',
        'attribute_allowlist' => [],
        'db_statement_export' => 'none',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'enabled' => true,
        'endpoint' => '',
        'collect_interval_ms' => 60000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    */
    'logs' => [
        'enabled' => true,
        'endpoint' => '',
        'min_level' => 'warning',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sampler
    |--------------------------------------------------------------------------
    | Supported types: "always", "never", "probability", "rate_limited",
    | "parent_based"
    | Override: OTEL_TRACES_SAMPLER, OTEL_TRACES_SAMPLER_ARG
    */
    'sampler' => [
        'type' => 'parent_based',
        'probability' => 1.0,
        'rate_per_second' => 100.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Propagators
    |--------------------------------------------------------------------------
    | Propagation formats injected/extracted from request headers.
    */
    'propagators' => ['tracecontext', 'baggage'],

    /*
    |--------------------------------------------------------------------------
    | Batch Exporter
    |--------------------------------------------------------------------------
    */
    'batch' => [
        'max_batch_size' => 512,
        'max_queue_size' => 2048,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cardinality Limiting
    |--------------------------------------------------------------------------
    */
    'cardinality' => [
        'max_attribute_keys' => 1000,
        'max_metric_series' => 2000,
        'normalize_urls' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dual Export
    |--------------------------------------------------------------------------
    | Keeps existing span processor (e.g., Studio) active alongside OTLP
    | export. Useful for debugging or local development with Pulsar Studio.
    */
    'dual_export' => false,
];
