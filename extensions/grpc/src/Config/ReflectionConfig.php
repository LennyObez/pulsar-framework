<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for gRPC server reflection.
 *
 * Reflection allows tools like grpcurl to discover services.
 * Disabled by default in production; enabling emits a security event.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ReflectionConfig
{
    public function __construct(
        public bool $enabled = false,
        public bool $allowInProduction = false,
    ) {}

    /**
     * @param array{
     *     enabled?: bool,
     *     allow_in_production?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? false,
            allowInProduction: $data['allow_in_production'] ?? false,
        );
    }
}
