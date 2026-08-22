<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for the gRPC health check service.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HealthConfig
{
    public function __construct(
        public bool $enabled = true,
    ) {}

    /**
     * @param array{enabled?: bool} $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? true,
        );
    }
}
