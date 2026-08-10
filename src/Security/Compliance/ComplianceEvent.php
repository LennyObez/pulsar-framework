<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Event\EnvelopeRequiredEvent;

/**
 * Abstract base for all compliance-related events.
 *
 * Provides common fields (eventId, occurredAt, correlationId, nonce) and enforces
 * regulation identification and event typing on all concrete implementations.
 *
 * Every event carries a nonce and timestamp for replay safety.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
abstract readonly class ComplianceEvent implements EnvelopeRequiredEvent
{
    public function __construct(
        public string $eventId,
        public DateTimeImmutable $occurredAt,
        public string $correlationId,
        public string $nonce,
    ) {}

    /**
     * The regulation this event supports controls for (e.g. 'gdpr', 'hipaa').
     */
    abstract public function regulation(): string;

    /**
     * The specific event type within the regulation (e.g. 'consent_granted').
     */
    abstract public function eventType(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * Build the common base fields array for serialization.
     *
     * @return array<string, mixed>
     */
    protected function baseToArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'correlation_id' => $this->correlationId,
            'nonce' => $this->nonce,
            'regulation' => $this->regulation(),
            'event_type' => $this->eventType(),
        ];
    }
}
