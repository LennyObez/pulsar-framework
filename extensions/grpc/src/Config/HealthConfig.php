<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;

/**
 * Configuration for the gRPC health check service.
 */
#[Api(since: '1.0.0')]
final readonly class HealthConfig
{
    public function __construct(
        public bool $enabled = true,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: is_bool($data['enabled'] ?? null) ? $data['enabled'] : true,
        );
    }
}
