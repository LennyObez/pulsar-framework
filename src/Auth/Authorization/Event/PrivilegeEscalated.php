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
use function is_array;
use function is_string;

/**
 * Dispatched when a user's roles are escalated during a session.
 *
 * Supports controls for SOX segregation of duties monitoring
 * and PCI-DSS privileged access tracking.
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
        $occurredAt = is_string($data['occurred_at'] ?? null)
            ? new DateTimeImmutable($data['occurred_at'])
            : new DateTimeImmutable();

        $fromRolesRaw = is_array($data['from_roles'] ?? null) ? $data['from_roles'] : [];
        /** @var list<string> $fromRoles */
        $fromRoles = [];
        foreach ($fromRolesRaw as $role) {
            if (is_string($role)) {
                $fromRoles[] = $role;
            }
        }

        $toRolesRaw = is_array($data['to_roles'] ?? null) ? $data['to_roles'] : [];
        /** @var list<string> $toRoles */
        $toRoles = [];
        foreach ($toRolesRaw as $role) {
            if (is_string($role)) {
                $toRoles[] = $role;
            }
        }

        return new self(
            identityId: is_string($data['identity_id'] ?? null) ? $data['identity_id'] : '',
            fromRoles: $fromRoles,
            toRoles: $toRoles,
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : '',
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            occurredAt: $occurredAt,
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
