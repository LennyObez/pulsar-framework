<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;

/**
 * Configuration for gRPC server reflection.
 *
 * Reflection allows tools like grpcurl to discover services.
 * Disabled by default in production; enabling emits a security event.
 */
#[Api(since: '1.0.0')]
final readonly class ReflectionConfig
{
    public function __construct(
        public bool $enabled = false,
        public bool $allowInProduction = false,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: is_bool($data['enabled'] ?? null) ? $data['enabled'] : false,
            allowInProduction: is_bool($data['allow_in_production'] ?? null) ? $data['allow_in_production'] : false,
        );
    }
}
