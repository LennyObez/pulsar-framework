<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for health checks.
 */
#[Api(since: '1.0.0')]
final readonly class HealthCheckConfig
{
    public function __construct(
        public int $intervalSeconds = 30,
        public int $timeoutSeconds = 5,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var int $intervalSeconds */
        $intervalSeconds = $data['interval_seconds'] ?? 30;
        /** @var int $timeoutSeconds */
        $timeoutSeconds = $data['timeout_seconds'] ?? 5;

        return new self(
            intervalSeconds: $intervalSeconds,
            timeoutSeconds: $timeoutSeconds,
        );
    }
}
