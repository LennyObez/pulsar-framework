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
use function is_int;
use function is_string;

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
            detectedBy: is_string($data['detected_by'] ?? null) ? $data['detected_by'] : '',
            affectedSubjectCount: is_int($data['affected_subject_count'] ?? null) ? $data['affected_subject_count'] : 0,
            dataCategories: $dataCategories,
            severity: is_string($data['severity'] ?? null) ? $data['severity'] : '',
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
        );
    }
}
