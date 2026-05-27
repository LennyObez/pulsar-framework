<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Support\Coerce;

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
        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null, true),
            endpoint: Coerce::string($data['endpoint'] ?? null),
            minLevel: LogLevel::tryFrom(Coerce::string($data['min_level'] ?? null)) ?? LogLevel::Warning,
        );
    }
}
