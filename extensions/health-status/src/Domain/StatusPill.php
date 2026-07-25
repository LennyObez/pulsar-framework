<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Domain;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Resilience\HealthCheck\HealthStatus;

use function in_array;
use function is_array;
use function is_string;

/**
 * A compact, site-wide status indicator for a footer/header pill.
 *
 * Distinct from {@see HealthStatus}: it adds an `unknown` state so the pill can
 * fail open to a neutral indicator (no history yet, or a store error) rather than
 * ever showing a false "operational".
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StatusPill
{
    public const string UNKNOWN = 'unknown';
    public const string HEALTHY = 'healthy';
    public const string DEGRADED = 'degraded';
    public const string UNHEALTHY = 'unhealthy';

    public function __construct(
        public string $status,
        public string $label,
    ) {}

    #[NoDiscard]
    public static function unknown(): self
    {
        return new self(self::UNKNOWN, 'Unknown');
    }

    #[NoDiscard]
    public static function fromHealthStatus(HealthStatus $status): self
    {
        return match ($status) {
            HealthStatus::Healthy => new self(self::HEALTHY, 'Operational'),
            HealthStatus::Degraded => new self(self::DEGRADED, 'Degraded'),
            HealthStatus::Unhealthy => new self(self::UNHEALTHY, 'Outage'),
        };
    }

    /**
     * @return array{status: string, label: string}
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return ['status' => $this->status, 'label' => $this->label];
    }

    /**
     * Rebuild from a cached array, falling back to unknown on any malformed shape.
     */
    #[NoDiscard]
    public static function fromArray(mixed $data): self
    {
        if (
            is_array($data)
            && isset($data['status'], $data['label'])
            && is_string($data['status'])
            && is_string($data['label'])
            && in_array($data['status'], [self::UNKNOWN, self::HEALTHY, self::DEGRADED, self::UNHEALTHY], true)
        ) {
            return new self($data['status'], $data['label']);
        }

        return self::unknown();
    }
}
