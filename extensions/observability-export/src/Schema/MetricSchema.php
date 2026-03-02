<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Export\JsonLines\Schema;

use Pulsar\Api\Internal;
use Pulsar\Observability\Metrics\MetricSnapshot;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Serializes a MetricSnapshot to a stable JSON structure with schema versioning.
 */
#[Internal(reason: 'Serialization detail; use MetricsExporterInterface instead')]
final readonly class MetricSchema
{
    private const string SCHEMA_VERSION = '1.0.0';

    /**
     * Serialize a metric snapshot to a stable associative array.
     *
     * @return array<string, mixed>
     */
    public static function toArray(MetricSnapshot $snapshot): array
    {
        $data = [
            'schema_version' => self::SCHEMA_VERSION,
            'name' => $snapshot->name,
            'type' => $snapshot->type->value,
            'help' => $snapshot->help,
            'values' => $snapshot->values,
        ];

        if ($snapshot->series !== null) {
            $data['series'] = $snapshot->series;
        }

        return $data;
    }

    /**
     * Serialize a metric snapshot to a JSON string.
     */
    public static function toJson(MetricSnapshot $snapshot): string
    {
        return json_encode(self::toArray($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
