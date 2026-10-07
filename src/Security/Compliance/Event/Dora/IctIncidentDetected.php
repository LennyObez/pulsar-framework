<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Dora;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records detection of an ICT-related incident.
 *
 * Supports controls for DORA Article 17 ICT-related incident management.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class IctIncidentDetected extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $affectedSystems
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $reporterIdentity,
        public string $incidentType,
        public string $severity,
        public array $affectedSystems,
        public string $description,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'dora';
    }

    public function eventType(): string
    {
        return 'ict_incident_detected';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'reporter_identity' => $this->reporterIdentity,
            'incident_type' => $this->incidentType,
            'severity' => $this->severity,
            'affected_systems' => $this->affectedSystems,
            'description' => $this->description,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     reporter_identity?: string,
     *     incident_type?: string,
     *     severity?: string,
     *     affected_systems?: list<string>,
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
            reporterIdentity: $data['reporter_identity'] ?? '',
            incidentType: $data['incident_type'] ?? '',
            severity: $data['severity'] ?? '',
            affectedSystems: $data['affected_systems'] ?? [],
            description: $data['description'] ?? '',
        );
    }
}
