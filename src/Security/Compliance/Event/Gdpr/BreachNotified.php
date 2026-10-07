<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records that a supervisory authority has been notified of a breach.
 *
 * Supports controls for GDPR Article 33 notification to supervisory authority within 72 hours.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class BreachNotified extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $authority,
        public DateTimeImmutable $notifiedAt,
        public string $breachEventId,
        public DateTimeImmutable $responseDeadline,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'gdpr';
    }

    public function eventType(): string
    {
        return 'breach_notified';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'authority' => $this->authority,
            'notified_at' => $this->notifiedAt->format('Y-m-d\TH:i:s.uP'),
            'breach_event_id' => $this->breachEventId,
            'response_deadline' => $this->responseDeadline->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     authority?: string,
     *     notified_at?: string,
     *     breach_event_id?: string,
     *     response_deadline?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $occurredAt = $data['occurred_at'] ?? null;
        $notifiedAt = $data['notified_at'] ?? null;
        $responseDeadline = $data['response_deadline'] ?? null;

        return new self(
            eventId: $data['event_id'] ?? '',
            occurredAt: $occurredAt !== null ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
            correlationId: $data['correlation_id'] ?? '',
            nonce: $data['nonce'] ?? '',
            authority: $data['authority'] ?? '',
            notifiedAt: $notifiedAt !== null ? new DateTimeImmutable($notifiedAt) : new DateTimeImmutable(),
            breachEventId: $data['breach_event_id'] ?? '',
            responseDeadline: $responseDeadline !== null ? new DateTimeImmutable($responseDeadline) : new DateTimeImmutable(),
        );
    }
}
