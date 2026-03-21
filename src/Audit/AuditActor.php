<?php

declare(strict_types=1);

namespace Pulsar\Audit;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Stringable;

use function trim;

/**
 * Identifies the actor responsible for an audited action.
 *
 * `AuditLogger` requires an explicit actor on every call so unauthenticated
 * actions cannot silently masquerade as a generic "system" identity. Use the
 * named factories (`user`, `serviceAccount`, `system`, `anonymous`) to
 * communicate the intended classification.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuditActor implements Stringable
{
    public function __construct(
        public AuditActorKind $kind,
        public string $id,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException(
                'AuditActor id must be a non-empty string',
            );
        }
    }

    /**
     * Authenticated end-user (human or programmatic on behalf of a user).
     */
    #[NoDiscard]
    public static function user(string $id): self
    {
        return new self(AuditActorKind::User, $id);
    }

    /**
     * Machine-to-machine identity (API client, signed service-to-service).
     */
    #[NoDiscard]
    public static function serviceAccount(string $id): self
    {
        return new self(AuditActorKind::ServiceAccount, $id);
    }

    /**
     * Internal background process or scheduler (no human attribution).
     *
     * `$component` SHOULD be a stable, descriptive identifier such as
     * `'mail.webhook'`, `'queue.tenant_fanout'`, `'tenancy.guard'`. The
     * canonical actor id is rendered as `system:<component>`.
     */
    #[NoDiscard]
    public static function system(string $component = 'unknown'): self
    {
        return new self(AuditActorKind::System, 'system:' . $component);
    }

    /**
     * Unauthenticated request whose origin cannot be attributed.
     */
    #[NoDiscard]
    public static function anonymous(): self
    {
        return new self(AuditActorKind::Anonymous, 'anonymous');
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
