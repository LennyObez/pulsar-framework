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
 * @api
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
     * @param array{
     *     id: string,
     *     check_name: string,
     *     severity: string,
     *     status: string,
     *     message: string,
     *     started_at: string,
     *     acknowledged_at?: string|null,
     *     resolved_at?: string|null,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $acknowledgedRaw = $data['acknowledged_at'] ?? null;
        $resolvedRaw = $data['resolved_at'] ?? null;

        return new self(
            id: $data['id'],
            checkName: $data['check_name'],
            severity: IncidentSeverity::from($data['severity']),
            status: IncidentStatus::from($data['status']),
            message: $data['message'],
            startedAt: new DateTimeImmutable($data['started_at']),
            acknowledgedAt: $acknowledgedRaw !== null ? new DateTimeImmutable($acknowledgedRaw) : null,
            resolvedAt: $resolvedRaw !== null ? new DateTimeImmutable($resolvedRaw) : null,
        );
    }
}
