<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization\Event;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Event\EnvelopeRequiredEvent;
use Pulsar\Support\Coerce;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $occurredAt = $data['occurred_at'] ?? null;

        return new self(
            attemptedIdentity: Coerce::string($data['attempted_identity'] ?? null),
            guardName: Coerce::string($data['guard_name'] ?? null),
            failureReason: Coerce::string($data['failure_reason'] ?? null),
            correlationId: Coerce::string($data['correlation_id'] ?? null),
            nonce: Coerce::string($data['nonce'] ?? null),
            occurredAt: is_string($occurredAt) ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
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
