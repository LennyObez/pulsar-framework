<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Psd2;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records creation of an SCA dynamic linking challenge.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class ScaChallengeCreated extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $challengeId,
        public string $transactionId,
        public string $challengeType,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'psd2';
    }

    public function eventType(): string
    {
        return 'sca_challenge_created';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'challenge_id' => $this->challengeId,
            'transaction_id' => $this->transactionId,
            'challenge_type' => $this->challengeType,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     challenge_id?: string,
     *     transaction_id?: string,
     *     challenge_type?: string,
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
            challengeId: $data['challenge_id'] ?? '',
            transactionId: $data['transaction_id'] ?? '',
            challengeType: $data['challenge_type'] ?? '',
        );
    }
}
