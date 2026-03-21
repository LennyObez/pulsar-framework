<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/resilience.php`.
 *
 * Composes retry, circuit breaker, and health check sub-configs.
 */
#[Api(since: '1.0.0')]
final readonly class ResilienceConfig
{
    public function __construct(
        public bool $enabled = false,
        public RetryConfig $retry = new RetryConfig(),
        public CircuitBreakerConfig $circuitBreaker = new CircuitBreakerConfig(),
        public HealthCheckConfig $healthCheck = new HealthCheckConfig(),
    ) {}

    /**
     * @param array<string, mixed> $data Raw array from config/resilience.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('RESILIENCE_ENABLED') !== null
            ? $environment->get('RESILIENCE_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        /** @var array<string, mixed> $retryData */
        $retryData = $data['retry'] ?? [];

        /** @var array<string, mixed> $cbData */
        $cbData = $data['circuit_breaker'] ?? [];

        /** @var array<string, mixed> $hcData */
        $hcData = $data['health_check'] ?? [];

        return new self(
            enabled: $enabled,
            retry: RetryConfig::fromArray($retryData),
            circuitBreaker: CircuitBreakerConfig::fromArray($cbData),
            healthCheck: HealthCheckConfig::fromArray($hcData),
        );
    }
}
