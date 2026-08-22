<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records a HIPAA breach notification event.
 *
 * Supports controls for HIPAA Breach Notification Rule (45 CFR 164.400-414).
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class BreachNotification extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $phiCategories
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $reporterIdentity,
        public int $affectedCount,
        public array $phiCategories,
        public DateTimeImmutable $discoveryDate,
        public DateTimeImmutable $notificationDeadline,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'hipaa';
    }

    public function eventType(): string
    {
        return 'breach_notification';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'reporter_identity' => $this->reporterIdentity,
            'affected_count' => $this->affectedCount,
            'phi_categories' => $this->phiCategories,
            'discovery_date' => $this->discoveryDate->format('Y-m-d\TH:i:s.uP'),
            'notification_deadline' => $this->notificationDeadline->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     reporter_identity?: string,
     *     affected_count?: int,
     *     phi_categories?: list<string>,
     *     discovery_date?: string,
     *     notification_deadline?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $occurredAt = $data['occurred_at'] ?? null;
        $discoveryDate = $data['discovery_date'] ?? null;
        $notificationDeadline = $data['notification_deadline'] ?? null;

        return new self(
            eventId: $data['event_id'] ?? '',
            occurredAt: $occurredAt !== null ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
            correlationId: $data['correlation_id'] ?? '',
            nonce: $data['nonce'] ?? '',
            reporterIdentity: $data['reporter_identity'] ?? '',
            affectedCount: $data['affected_count'] ?? 0,
            phiCategories: $data['phi_categories'] ?? [],
            discoveryDate: $discoveryDate !== null ? new DateTimeImmutable($discoveryDate) : new DateTimeImmutable(),
            notificationDeadline: $notificationDeadline !== null ? new DateTimeImmutable($notificationDeadline) : new DateTimeImmutable(),
        );
    }
}
