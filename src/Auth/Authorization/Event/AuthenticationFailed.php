<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization\Event;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Event\EnvelopeRequiredEvent;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;

/**
 * Dispatched when authentication fails.
 *
 * Supports controls for HIPAA access logging and intrusion detection.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class AuthenticationFailed implements EnvelopeRequiredEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public string $attemptedIdentity,
        public string $guardName,
        public string $failureReason,
        public string $correlationId,
        public string $nonce,
        public DateTimeImmutable $occurredAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attempted_identity' => $this->attemptedIdentity,
            'guard_name' => $this->guardName,
            'failure_reason' => $this->failureReason,
            'correlation_id' => $this->correlationId,
            'nonce' => $this->nonce,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'schema_version' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param array{
     *     attempted_identity?: string,
     *     guard_name?: string,
     *     failure_reason?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     occurred_at?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $occurredAt = $data['occurred_at'] ?? null;

        return new self(
            attemptedIdentity: $data['attempted_identity'] ?? '',
            guardName: $data['guard_name'] ?? '',
            failureReason: $data['failure_reason'] ?? '',
            correlationId: $data['correlation_id'] ?? '',
            nonce: $data['nonce'] ?? '',
            occurredAt: $occurredAt !== null ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
        );
    }

    #[NoDiscard]
    public static function create(
        string $attemptedIdentity,
        string $guardName,
        string $failureReason,
        string $correlationId,
        ?Randomizer $randomizer = null,
    ): self {
        $randomizer ??= new Randomizer(new Secure());

        return new self(
            attemptedIdentity: $attemptedIdentity,
            guardName: $guardName,
            failureReason: $failureReason,
            correlationId: $correlationId,
            nonce: bin2hex($randomizer->getBytes(16)),
            occurredAt: new DateTimeImmutable(),
        );
    }
}
