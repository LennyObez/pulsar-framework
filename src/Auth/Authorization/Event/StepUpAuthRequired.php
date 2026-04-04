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
 * Dispatched when a step-up authentication is required for an operation.
 *
 * Supports controls for zero-trust architecture requirements
 * and PCI-DSS multi-factor authentication policies.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class StepUpAuthRequired implements EnvelopeRequiredEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public string $identityId,
        public string $permission,
        public float $trustScore,
        public string $requiredLevel,
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
            'permission' => $this->permission,
            'trust_score' => $this->trustScore,
            'required_level' => $this->requiredLevel,
            'correlation_id' => $this->correlationId,
            'nonce' => $this->nonce,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'schema_version' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param array{
     *     identity_id?: string,
     *     permission?: string,
     *     trust_score?: float|int,
     *     required_level?: string,
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
            identityId: $data['identity_id'] ?? '',
            permission: $data['permission'] ?? '',
            trustScore: (float) ($data['trust_score'] ?? 0.0),
            requiredLevel: $data['required_level'] ?? '',
            correlationId: $data['correlation_id'] ?? '',
            nonce: $data['nonce'] ?? '',
            occurredAt: $occurredAt !== null ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
        );
    }

    #[NoDiscard]
    public static function create(
        string $identityId,
        string $permission,
        float $trustScore,
        string $requiredLevel,
        string $correlationId,
        ?Randomizer $randomizer = null,
    ): self {
        $randomizer ??= new Randomizer(new Secure());

        return new self(
            identityId: $identityId,
            permission: $permission,
            trustScore: $trustScore,
            requiredLevel: $requiredLevel,
            correlationId: $correlationId,
            nonce: bin2hex($randomizer->getBytes(16)),
            occurredAt: new DateTimeImmutable(),
        );
    }
}
