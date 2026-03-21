<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Domain;

use Pulsar\Api\Api;

/**
 * Result of a single health check execution.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HealthCheckResult
{
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public float $latencyMs,
        public string $message = '',
    ) {}

    /**
     * @return array{name: string, status: string, latency_ms: float, message: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'latency_ms' => $this->latencyMs,
            'message' => $this->message,
        ];
    }
}
