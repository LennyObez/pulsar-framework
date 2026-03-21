<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess\Event;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

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
            justificationId: is_string($data['justification_id'] ?? null) ? $data['justification_id'] : '',
            reviewerId: is_string($data['reviewer_id'] ?? null) ? $data['reviewer_id'] : '',
            previousStatus: is_string($data['previous_status'] ?? null) ? $data['previous_status'] : '',
            newStatus: is_string($data['new_status'] ?? null) ? $data['new_status'] : '',
            originalActorId: is_string($data['original_actor_id'] ?? null) ? $data['original_actor_id'] : '',
        );
    }
}
