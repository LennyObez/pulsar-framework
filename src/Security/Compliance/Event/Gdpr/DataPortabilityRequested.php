<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records that a data portability request has been filed.
 *
 * Supports controls for GDPR Article 20 right to data portability.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class DataPortabilityRequested extends ComplianceEvent
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
        public string $format,
        public array $dataCategories,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'gdpr';
    }

    public function eventType(): string
    {
        return 'data_portability_requested';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'subject_id' => $this->subjectId,
            'requester_identity' => $this->requesterIdentity,
            'format' => $this->format,
            'data_categories' => $this->dataCategories,
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
     *     format?: string,
     *     data_categories?: list<string>,
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
            format: $data['format'] ?? '',
            dataCategories: $data['data_categories'] ?? [],
        );
    }
}
