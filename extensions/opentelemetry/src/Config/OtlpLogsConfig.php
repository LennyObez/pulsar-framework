<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Observability\Log\LogLevel;

use function is_bool;
use function is_string;

/**
 * Logs-specific OTLP configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class OtlpLogsConfig
{
    public function __construct(
        public bool $enabled = true,
        public string $endpoint = '',
        public LogLevel $minLevel = LogLevel::Warning,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawEnabled = $data['enabled'] ?? true;
        $rawEndpoint = $data['endpoint'] ?? '';
        $rawMinLevel = $data['min_level'] ?? 'warning';

        $minLevel = LogLevel::Warning;

        if (is_string($rawMinLevel)) {
            $minLevel = LogLevel::tryFrom($rawMinLevel) ?? LogLevel::Warning;
        }

        return new self(
            enabled: is_bool($rawEnabled) ? $rawEnabled : true,
            endpoint: is_string($rawEndpoint) ? $rawEndpoint : '',
            minLevel: $minLevel,
        );
    }
}
