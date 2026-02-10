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
use function is_string;

/**
 * Dispatched when authentication succeeds.
 *
 * Supports controls for HIPAA access logging and SOX audit trail requirements.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class AuthenticationSucceeded implements EnvelopeRequiredEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public string $identityId,
        public string $guardName,
        public string $method,
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
            'identity_id' => $this->identityId,
            'guard_name' => $this->guardName,
            'method' => $this->method,
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
        $occurredAt = is_string($data['occurred_at'] ?? null)
            ? new DateTimeImmutable($data['occurred_at'])
            : new DateTimeImmutable();

        return new self(
            identityId: is_string($data['identity_id'] ?? null) ? $data['identity_id'] : '',
            guardName: is_string($data['guard_name'] ?? null) ? $data['guard_name'] : '',
            method: is_string($data['method'] ?? null) ? $data['method'] : '',
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            occurredAt: $occurredAt,
        );
    }

    #[NoDiscard]
    public static function create(
        string $identityId,
        string $guardName,
        string $method,
        string $correlationId,
        ?Randomizer $randomizer = null,
    ): self {
        $randomizer ??= new Randomizer(new Secure());

        return new self(
            identityId: $identityId,
            guardName: $guardName,
            method: $method,
            correlationId: $correlationId,
            nonce: bin2hex($randomizer->getBytes(16)),
            occurredAt: new DateTimeImmutable(),
        );
    }
}
