<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExport\Schema;

use Pulsar\Api\Internal;
use Pulsar\Observability\Tracing\Span;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Serializes a Span to a stable JSON structure with schema versioning.
 */
#[Internal(reason: 'Serialization detail; use SpanExporterInterface instead')]
final readonly class SpanSchema
{
    private const string SCHEMA_VERSION = '1.0.0';

    /**
     * Serialize a span to a stable associative array.
     *
     * @return array<string, mixed>
     */
    public static function toArray(Span $span): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'trace_id' => $span->context->traceId->value,
            'span_id' => $span->context->spanId->value,
            'parent_span_id' => $span->parentSpanId?->value,
            'name' => $span->name,
            'status' => $span->status->value,
            'start_time_ns' => $span->startTime(),
            'end_time_ns' => $span->endTime(),
            'duration_ns' => $span->duration(),
            'attributes' => $span->attributes(),
        ];
    }

    /**
     * Serialize a span to a JSON string.
     */
    public static function toJson(Span $span): string
    {
        return json_encode(self::toArray($span), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
