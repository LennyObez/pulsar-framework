<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;

/**
 * Toggle configuration for individual interceptors.
 *
 * The interceptor execution order is fixed (Tracing -> Auth -> RateLimit ->
 * Validation -> Logging) and cannot be changed. This config only controls
 * which interceptors are active.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class InterceptorToggleConfig
{
    public function __construct(
        public bool $tracing = true,
        public bool $auth = true,
        public bool $rateLimit = true,
        public bool $validation = true,
        public bool $logging = true,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            tracing: is_bool($data['tracing'] ?? null) ? $data['tracing'] : true,
            auth: is_bool($data['auth'] ?? null) ? $data['auth'] : true,
            rateLimit: is_bool($data['rate_limit'] ?? null) ? $data['rate_limit'] : true,
            validation: is_bool($data['validation'] ?? null) ? $data['validation'] : true,
            logging: is_bool($data['logging'] ?? null) ? $data['logging'] : true,
        );
    }
}
