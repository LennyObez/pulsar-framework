<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess\Event;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Emitted when a compliance officer reviews an access justification.
 *
 * Supports audit trail requirements for PCI-DSS, HIPAA, and SOC 2.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class AccessJustificationReviewed extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $justificationId,
        public string $reviewerId,
        public string $previousStatus,
        public string $newStatus,
        public string $originalActorId,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'multi';
    }

    public function eventType(): string
    {
        return 'access_justification_reviewed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'justification_id' => $this->justificationId,
            'reviewer_id' => $this->reviewerId,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
            'original_actor_id' => $this->originalActorId,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     justification_id?: string,
     *     reviewer_id?: string,
     *     previous_status?: string,
     *     new_status?: string,
     *     original_actor_id?: string,
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
            justificationId: $data['justification_id'] ?? '',
            reviewerId: $data['reviewer_id'] ?? '',
            previousStatus: $data['previous_status'] ?? '',
            newStatus: $data['new_status'] ?? '',
            originalActorId: $data['original_actor_id'] ?? '',
        );
    }
}
