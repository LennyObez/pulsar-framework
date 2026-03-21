<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Psd2;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

/**
 * Records successful verification of an SCA challenge.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class ScaChallengeVerified extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $challengeId,
        public string $transactionId,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'psd2';
    }

    public function eventType(): string
    {
        return 'sca_challenge_verified';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'challenge_id' => $this->challengeId,
            'transaction_id' => $this->transactionId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            challengeId: is_string($data['challenge_id'] ?? null) ? $data['challenge_id'] : '',
            transactionId: is_string($data['transaction_id'] ?? null) ? $data['transaction_id'] : '',
        );
    }
}
