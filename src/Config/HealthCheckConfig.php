<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for health checks.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HealthCheckConfig
{
    public function __construct(
        public int $intervalSeconds = 30,
        public int $timeoutSeconds = 5,
    ) {}

    /**
     * @param array{
     *     interval_seconds?: int,
     *     timeout_seconds?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            intervalSeconds: $data['interval_seconds'] ?? 30,
            timeoutSeconds: $data['timeout_seconds'] ?? 5,
        );
    }
}
