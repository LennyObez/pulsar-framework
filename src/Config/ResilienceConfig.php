<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/resilience.php`.
 *
 * Composes retry, circuit breaker, and health check sub-configs.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ResilienceConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/resilience.php. */
    private const array KNOWN_KEYS = ['enabled', 'circuit_breaker', 'retry', 'health_check'];

    public function __construct(
        public bool $enabled = false,
        public RetryConfig $retry = new RetryConfig(),
        public CircuitBreakerConfig $circuitBreaker = new CircuitBreakerConfig(),
        public HealthCheckConfig $healthCheck = new HealthCheckConfig(),
        /** @var list<string> */
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     retry?: array<string, mixed>,
     *     circuit_breaker?: array<string, mixed>,
     *     health_check?: array<string, mixed>,
     * } $data Raw array from config/resilience.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('RESILIENCE_ENABLED') !== null
            ? $environment->get('RESILIENCE_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        $retry = RetryConfig::fromArray($data['retry'] ?? []);
        $circuitBreaker = CircuitBreakerConfig::fromArray($data['circuit_breaker'] ?? []);
        $healthCheck = HealthCheckConfig::fromArray($data['health_check'] ?? []);

        return new self(
            enabled: $enabled,
            retry: $retry,
            circuitBreaker: $circuitBreaker,
            healthCheck: $healthCheck,
            unknownKeys: [
                ...UnknownKeys::collect($data, self::KNOWN_KEYS),
                ...UnknownKeys::nested('retry', $retry),
                ...UnknownKeys::nested('circuit_breaker', $circuitBreaker),
                ...UnknownKeys::nested('health_check', $healthCheck),
            ],
        );
    }
}
