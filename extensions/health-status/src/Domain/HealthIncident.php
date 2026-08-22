<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Domain;

use Pulsar\Api\Api;

/**
 * Represents an active or recent health incident.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HealthIncident
{
    public function __construct(
        public string $checkName,
        public IncidentSeverity $severity,
        public string $message,
        public int $startedAt,
        public ?int $resolvedAt = null,
    ) {}

    public function isActive(): bool
    {
        return $this->resolvedAt === null;
    }

    public function durationSeconds(): int
    {
        $end = $this->resolvedAt ?? time();

        return $end - $this->startedAt;
    }

    /**
     * @return array{check_name: string, severity: string, message: string, started_at: int, resolved_at: int|null, active: bool, duration_seconds: int}
     */
    public function toArray(): array
    {
        return [
            'check_name' => $this->checkName,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'started_at' => $this->startedAt,
            'resolved_at' => $this->resolvedAt,
            'active' => $this->isActive(),
            'duration_seconds' => $this->durationSeconds(),
        ];
    }
}
