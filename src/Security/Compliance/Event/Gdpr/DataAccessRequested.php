<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function array_values;
use function is_array;
use function is_string;

/**
 * Records that a data subject access request (DSAR) has been filed.
 *
 * Supports controls for GDPR Article 15 right of access.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class DataAccessRequested extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $dataCategories
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $subjectId,
        public string $requesterIdentity,
        public array $dataCategories,
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
        return 'data_access_requested';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'subject_id' => $this->subjectId,
            'requester_identity' => $this->requesterIdentity,
            'data_categories' => $this->dataCategories,
            'legal_basis' => $this->legalBasis,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $dataCategories */
        $dataCategories = is_array($data['data_categories'] ?? null) ? array_values($data['data_categories']) : [];

        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            subjectId: is_string($data['subject_id'] ?? null) ? $data['subject_id'] : '',
            requesterIdentity: is_string($data['requester_identity'] ?? null) ? $data['requester_identity'] : '',
            dataCategories: $dataCategories,
            legalBasis: is_string($data['legal_basis'] ?? null) ? $data['legal_basis'] : '',
        );
    }
}
