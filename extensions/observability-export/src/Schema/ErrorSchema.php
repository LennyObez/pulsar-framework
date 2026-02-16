<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Export\JsonLines\Schema;

use Pulsar\Api\Internal;
use Pulsar\Observability\ErrorTracking\ErrorEvent;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Serializes an ErrorEvent to a stable JSON structure with schema versioning.
 */
#[Internal(reason: 'Serialization detail; use ErrorExporterInterface instead')]
final readonly class ErrorSchema
{
    private const string SCHEMA_VERSION = '1.0.0';

    /**
     * Serialize an error event to a stable associative array.
     *
     * @return array<string, mixed>
     */
    public static function toArray(ErrorEvent $event): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'fingerprint' => $event->fingerprint->value,
            'exception_class' => $event->exceptionClass,
            'message' => $event->message,
            'file' => $event->file,
            'line' => $event->line,
            'stack_trace' => $event->stackTrace,
            'context' => $event->context,
            'occurred_at' => $event->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'trace_id' => $event->traceId?->value,
        ];
    }

    /**
     * Serialize an error event to a JSON string.
     */
    public static function toJson(ErrorEvent $event): string
    {
        return json_encode(self::toArray($event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
