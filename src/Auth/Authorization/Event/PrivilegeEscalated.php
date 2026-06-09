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
 * Dispatched when a user's roles are escalated during a session.
 *
 * Supports controls for SOX segregation of duties monitoring
 * and PCI-DSS privileged access tracking.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class PrivilegeEscalated implements EnvelopeRequiredEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $fromRoles
     * @param list<string> $toRoles
     */
    public function __construct(
        public string $identityId,
        public array $fromRoles,
        public array $toRoles,
        public string $reason,
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
            'from_roles' => $this->fromRoles,
            'to_roles' => $this->toRoles,
            'reason' => $this->reason,
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
            identityId: Coerce::string($data['identity_id'] ?? null),
            fromRoles: Coerce::listOfString($data['from_roles'] ?? null),
            toRoles: Coerce::listOfString($data['to_roles'] ?? null),
            reason: Coerce::string($data['reason'] ?? null),
            correlationId: Coerce::string($data['correlation_id'] ?? null),
            nonce: Coerce::string($data['nonce'] ?? null),
            occurredAt: is_string($occurredAt) ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
        );
    }

    /**
     * @param list<string> $fromRoles
     * @param list<string> $toRoles
     */
    #[NoDiscard]
    public static function create(
        string $identityId,
        array $fromRoles,
        array $toRoles,
        string $reason,
        string $correlationId,
        ?Randomizer $randomizer = null,
    ): self {
        $randomizer ??= new Randomizer(new Secure());

        return new self(
            identityId: $identityId,
            fromRoles: $fromRoles,
            toRoles: $toRoles,
            reason: $reason,
            correlationId: $correlationId,
            nonce: bin2hex($randomizer->getBytes(16)),
            occurredAt: new DateTimeImmutable(),
        );
    }
}
