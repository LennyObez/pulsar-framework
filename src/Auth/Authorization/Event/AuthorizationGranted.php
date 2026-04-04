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
 * Dispatched when an authorization check grants access.
 *
 * Supports controls for audit trail requirements in SOX Section 302
 * and HIPAA access logging.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class AuthorizationGranted implements EnvelopeRequiredEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public string $identityId,
        public string $permission,
        public ?string $resource,
        public string $grantReason,
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
            'resource' => $this->resource,
            'grant_reason' => $this->grantReason,
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
     *     resource?: string|null,
     *     grant_reason?: string,
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
            resource: $data['resource'] ?? null,
            grantReason: $data['grant_reason'] ?? '',
            correlationId: $data['correlation_id'] ?? '',
            nonce: $data['nonce'] ?? '',
            occurredAt: $occurredAt !== null ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
        );
    }

    #[NoDiscard]
    public static function create(
        string $identityId,
        string $permission,
        ?string $resource,
        string $grantReason,
        string $correlationId,
        ?Randomizer $randomizer = null,
    ): self {
        $randomizer ??= new Randomizer(new Secure());

        return new self(
            identityId: $identityId,
            permission: $permission,
            resource: $resource,
            grantReason: $grantReason,
            correlationId: $correlationId,
            nonce: bin2hex($randomizer->getBytes(16)),
            occurredAt: new DateTimeImmutable(),
        );
    }
}
