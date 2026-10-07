<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records that a data deletion request (right to erasure) has been filed.
 *
 * Supports controls for GDPR Article 17 right to erasure.
 * @api
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
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     subject_id?: string,
     *     requester_identity?: string,
     *     deletion_scope?: string,
     *     legal_basis?: string,
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
            subjectId: $data['subject_id'] ?? '',
            requesterIdentity: $data['requester_identity'] ?? '',
            deletionScope: $data['deletion_scope'] ?? '',
            legalBasis: $data['legal_basis'] ?? '',
        );
    }
}
