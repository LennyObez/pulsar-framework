<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     tracing?: bool,
     *     auth?: bool,
     *     rate_limit?: bool,
     *     validation?: bool,
     *     logging?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            tracing: $data['tracing'] ?? true,
            auth: $data['auth'] ?? true,
            rateLimit: $data['rate_limit'] ?? true,
            validation: $data['validation'] ?? true,
            logging: $data['logging'] ?? true,
        );
    }
}
