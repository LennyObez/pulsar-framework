<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Observability\Log\LogLevel;

/**
 * Logs-specific configuration for the observability extension.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class LogsConfig
{
    public function __construct(
        public bool $enabled = true,
        public string $endpoint = '',
        public LogLevel $minLevel = LogLevel::Warning,
    ) {}

    /**
     * @param array{
     *     enabled?: bool,
     *     endpoint?: string,
     *     min_level?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? true,
            endpoint: $data['endpoint'] ?? '',
            minLevel: LogLevel::tryFrom($data['min_level'] ?? '') ?? LogLevel::Warning,
        );
    }
}
