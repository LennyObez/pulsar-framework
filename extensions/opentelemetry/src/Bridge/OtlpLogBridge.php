<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Bridge;

use JsonException;
use Override;
use Pulsar\Api\Api;
use Pulsar\Extension\OpenTelemetry\Internal\Export\LogsBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpLogRecord;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\LogSinkInterface;

use function hex2bin;
use function is_scalar;
use function is_string;
use function json_encode;
use function strtoupper;

use const JSON_THROW_ON_ERROR;

/**
 * Log sink that converts Pulsar log entries to OTLP format with
 * sensitive data scrubbing and trace correlation.
 */
#[Api(since: '1.0.0')]
final readonly class OtlpLogBridge implements LogSinkInterface
{
    public function __construct(
        private LogsBatchExporter $exporter,
        private SensitiveDataScrubber $scrubber,
        private ?CorrelationContextProviderInterface $correlationProvider = null,
        private LogLevel $minLevel = LogLevel::Warning,
    ) {}

    #[Override]
    public function write(LogEntry $entry): void
    {
        // H9: Filter by minimum severity level
        if (!$entry->level->meetsThreshold($this->minLevel)) {
            return;
        }

        $correlation = $this->correlationProvider?->current();

        $scrubbedContext = $this->scrubber->scrub($entry->context);

        // S1: Scrub sensitive data from log body
        $scrubbedBody = $this->scrubber->scrub(['body' => $entry->message]);

        $attributes = $this->flattenContext($scrubbedContext);
        $attributes['log.channel'] = $entry->channel;

        $record = new OtlpLogRecord(
            timeUnixNano: $entry->timestamp->getTimestamp() * 1_000_000_000,
            severityNumber: self::mapSeverityNumber($entry->level),
            severityText: strtoupper($entry->level->value),
            body: is_string($scrubbedBody['body']) ? $scrubbedBody['body'] : $entry->message,
            attributes: $attributes,
            traceId: $this->resolveTraceId($correlation),
            spanId: $this->resolveSpanId($correlation),
        );

        $this->exporter->enqueue($record);
    }

    private function resolveTraceId(?CorrelationContext $correlation): ?string
    {
        if ($correlation === null || $correlation->traceId === null) {
            return null;
        }

        $binary = hex2bin($correlation->traceId);

        return $binary !== false ? $binary : null;
    }

    private function resolveSpanId(?CorrelationContext $correlation): ?string
    {
        if ($correlation === null || $correlation->spanId === null) {
            return null;
        }

        $binary = hex2bin($correlation->spanId);

        return $binary !== false ? $binary : null;
    }

    /**
     * Flatten mixed context array to scalar-only attributes.
     *
     * @param array<string, mixed> $context
     * @return array<string, scalar>
     */
    private function flattenContext(array $context): array
    {
        $flat = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $flat[$key] = $value;
            } else {
                try {
                    $flat[$key] = json_encode($value, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $flat[$key] = '[unserializable]';
                }
            }
        }

        return $flat;
    }

    /**
     * Map Pulsar LogLevel to OTel severity number.
     *
     * @see https://opentelemetry.io/docs/specs/otel/logs/data-model/#severity-fields
     */
    private static function mapSeverityNumber(LogLevel $level): int
    {
        return match ($level) {
            LogLevel::Debug => 5,
            LogLevel::Info => 9,
            LogLevel::Notice => 10,
            LogLevel::Warning => 13,
            LogLevel::Error => 17,
            LogLevel::Critical => 21,
            LogLevel::Alert => 22,
            LogLevel::Emergency => 24,
        };
    }
}
