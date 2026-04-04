<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records that a personal data breach has been detected.
 *
 * Supports controls for GDPR Article 33 breach notification to supervisory authority.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class BreachDetected extends ComplianceEvent
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
        public string $detectedBy,
        public int $affectedSubjectCount,
        public array $dataCategories,
        public string $severity,
        public string $description,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'gdpr';
    }

    public function eventType(): string
    {
        return 'breach_detected';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'detected_by' => $this->detectedBy,
            'affected_subject_count' => $this->affectedSubjectCount,
            'data_categories' => $this->dataCategories,
            'severity' => $this->severity,
            'description' => $this->description,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     detected_by?: string,
     *     affected_subject_count?: int,
     *     data_categories?: list<string>,
     *     severity?: string,
     *     description?: string,
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
            detectedBy: $data['detected_by'] ?? '',
            affectedSubjectCount: $data['affected_subject_count'] ?? 0,
            dataCategories: $data['data_categories'] ?? [],
            severity: $data['severity'] ?? '',
            description: $data['description'] ?? '',
        );
    }
}
