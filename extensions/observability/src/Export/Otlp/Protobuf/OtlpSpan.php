<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\Otlp\Protobuf;

use Pulsar\Api\Internal;

/**
 * Internal span DTO for OTLP serialization (post-allowlist-filtering).
 *
 * All ID fields are raw binary bytes (not hex-encoded).
 */
#[Internal(reason: 'Wire-format DTO for OTLP span export')]
final readonly class OtlpSpan
{
    /**
     * @param string               $traceId         16-byte binary trace ID
     * @param string               $spanId          8-byte binary span ID
     * @param string|null          $parentSpanId    8-byte binary parent span ID, or null
     * @param string               $name            Span operation name
     * @param int                  $kind            SpanKind enum value (1 = INTERNAL)
     * @param int                  $startTimeUnixNano Start time as Unix epoch nanoseconds
     * @param int                  $endTimeUnixNano   End time as Unix epoch nanoseconds
     * @param array<string, scalar> $attributes       Span attributes
     * @param int                  $statusCode      Status code (0=unset, 1=ok, 2=error)
     * @param string               $statusMessage   Status description (typically set for errors)
     */
    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId,
        public string $name,
        public int $startTimeUnixNano,
        public int $endTimeUnixNano,
        public array $attributes,
        public int $statusCode,
        public string $statusMessage,
        public int $kind = OtlpFieldNumbers::SPAN_KIND_INTERNAL,
    ) {}
}
