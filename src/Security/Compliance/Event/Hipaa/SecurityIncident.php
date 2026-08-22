<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records a security incident affecting ePHI.
 *
 * Supports controls for HIPAA Security Rule incident response (45 CFR 164.308(a)(6)).
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class SecurityIncident extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $containmentActions
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $reporterIdentity,
        public string $incidentType,
        public string $severity,
        public string $description,
        public array $containmentActions,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'hipaa';
    }

    public function eventType(): string
    {
        return 'security_incident';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'reporter_identity' => $this->reporterIdentity,
            'incident_type' => $this->incidentType,
            'severity' => $this->severity,
            'description' => $this->description,
            'containment_actions' => $this->containmentActions,
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
     *     description?: string,
     *     containment_actions?: list<string>,
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
            description: $data['description'] ?? '',
            containmentActions: $data['containment_actions'] ?? [],
        );
    }
}
