<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Dora;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records initiation of a recovery procedure following an ICT incident.
 *
 * Supports controls for DORA Article 11 business continuity management.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class RecoveryInitiated extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $operatorIdentity,
        public string $incidentId,
        public string $recoveryPlan,
        public string $estimatedRecoveryTime,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'dora';
    }

    public function eventType(): string
    {
        return 'recovery_initiated';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'operator_identity' => $this->operatorIdentity,
            'incident_id' => $this->incidentId,
            'recovery_plan' => $this->recoveryPlan,
            'estimated_recovery_time' => $this->estimatedRecoveryTime,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     operator_identity?: string,
     *     incident_id?: string,
     *     recovery_plan?: string,
     *     estimated_recovery_time?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $occurredAt = $data['occurred_at'] ?? null;

        return new self(
            eventId: $data['event_id'] ?? '',
            occurredAt: $occurredAt !== null ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
            correlationId: $data['correlation_id'] ?? '',
            nonce: $data['nonce'] ?? '',
            operatorIdentity: $data['operator_identity'] ?? '',
            incidentId: $data['incident_id'] ?? '',
            recoveryPlan: $data['recovery_plan'] ?? '',
            estimatedRecoveryTime: $data['estimated_recovery_time'] ?? '',
        );
    }
}
