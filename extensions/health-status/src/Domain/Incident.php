<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Represents a health incident with full lifecycle tracking.
 *
 * Incidents are immutable. State transitions (acknowledge, resolve)
 * return new instances using clone-with semantics.
 */
#[Api(since: '1.0.0')]
final readonly class Incident
{
    public function __construct(
        public string $id,
        public string $checkName,
        public IncidentSeverity $severity,
        public IncidentStatus $status,
        public string $message,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $acknowledgedAt = null,
        public ?DateTimeImmutable $resolvedAt = null,
    ) {}

    /**
     * Acknowledge this incident, transitioning status to Acknowledged.
     */
    #[NoDiscard]
    public function acknowledge(DateTimeImmutable $at): self
    {
        return clone($this, [
            'status' => IncidentStatus::Acknowledged,
            'acknowledgedAt' => $at,
        ]);
    }

    /**
     * Resolve this incident, transitioning status to Resolved.
     */
    #[NoDiscard]
    public function resolve(DateTimeImmutable $at): self
    {
        return clone($this, [
            'status' => IncidentStatus::Resolved,
            'resolvedAt' => $at,
        ]);
    }

    /**
     * @return array{id: string, check_name: string, severity: string, status: string, message: string, started_at: string, acknowledged_at: string|null, resolved_at: string|null}
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'check_name' => $this->checkName,
            'severity' => $this->severity->value,
            'status' => $this->status->value,
            'message' => $this->message,
            'started_at' => $this->startedAt->format(DateTimeImmutable::ATOM),
            'acknowledged_at' => $this->acknowledgedAt?->format(DateTimeImmutable::ATOM),
            'resolved_at' => $this->resolvedAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var string|null $acknowledgedRaw */
        $acknowledgedRaw = $data['acknowledged_at'] ?? null;
        $acknowledgedAt = $acknowledgedRaw !== null
            ? new DateTimeImmutable($acknowledgedRaw)
            : null;

        /** @var string|null $resolvedRaw */
        $resolvedRaw = $data['resolved_at'] ?? null;
        $resolvedAt = $resolvedRaw !== null
            ? new DateTimeImmutable($resolvedRaw)
            : null;

        /** @var string $id */
        $id = $data['id'];
        /** @var string $checkName */
        $checkName = $data['check_name'];
        /** @var string $severity */
        $severity = $data['severity'];
        /** @var string $status */
        $status = $data['status'];
        /** @var string $message */
        $message = $data['message'];
        /** @var string $startedAt */
        $startedAt = $data['started_at'];

        return new self(
            id: $id,
            checkName: $checkName,
            severity: IncidentSeverity::from($severity),
            status: IncidentStatus::from($status),
            message: $message,
            startedAt: new DateTimeImmutable($startedAt),
            acknowledgedAt: $acknowledgedAt,
            resolvedAt: $resolvedAt,
        );
    }
}
