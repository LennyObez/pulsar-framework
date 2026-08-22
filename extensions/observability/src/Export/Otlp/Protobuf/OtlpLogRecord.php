<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\Otlp\Protobuf;

use Pulsar\Api\Internal;

/**
 * Internal log record DTO for OTLP serialization.
 *
 * Binary ID fields are raw bytes (not hex-encoded).
 */
#[Internal(reason: 'Wire-format DTO for OTLP log record export')]
final readonly class OtlpLogRecord
{
    /**
     * @param int                  $timeUnixNano   Log timestamp as Unix epoch nanoseconds
     * @param int                  $severityNumber OTel severity number (1-24)
     * @param string               $severityText   OTel severity text (e.g., "ERROR")
     * @param string               $body           Log message body
     * @param array<string, scalar> $attributes     Log attributes
     * @param string|null          $traceId        16-byte binary trace ID, or null
     * @param string|null          $spanId         8-byte binary span ID, or null
     */
    public function __construct(
        public int $timeUnixNano,
        public int $severityNumber,
        public string $severityText,
        public string $body,
        public array $attributes,
        public ?string $traceId,
        public ?string $spanId,
    ) {}
}
