<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Dora;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

/**
 * Records initiation of a recovery procedure following an ICT incident.
 *
 * Supports controls for DORA Article 11 business continuity management.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            operatorIdentity: is_string($data['operator_identity'] ?? null) ? $data['operator_identity'] : '',
            incidentId: is_string($data['incident_id'] ?? null) ? $data['incident_id'] : '',
            recoveryPlan: is_string($data['recovery_plan'] ?? null) ? $data['recovery_plan'] : '',
            estimatedRecoveryTime: is_string($data['estimated_recovery_time'] ?? null) ? $data['estimated_recovery_time'] : '',
        );
    }
}
