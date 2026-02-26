<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

/**
 * Records that a data deletion request (right to erasure) has been filed.
 *
 * Supports controls for GDPR Article 17 right to erasure.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class DataDeletionRequested extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $subjectId,
        public string $requesterIdentity,
        public string $deletionScope,
        public string $legalBasis,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'gdpr';
    }

    public function eventType(): string
    {
        return 'data_deletion_requested';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'subject_id' => $this->subjectId,
            'requester_identity' => $this->requesterIdentity,
            'deletion_scope' => $this->deletionScope,
            'legal_basis' => $this->legalBasis,
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
            subjectId: is_string($data['subject_id'] ?? null) ? $data['subject_id'] : '',
            requesterIdentity: is_string($data['requester_identity'] ?? null) ? $data['requester_identity'] : '',
            deletionScope: is_string($data['deletion_scope'] ?? null) ? $data['deletion_scope'] : '',
            legalBasis: is_string($data['legal_basis'] ?? null) ? $data['legal_basis'] : '',
        );
    }
}
