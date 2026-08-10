<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use Override;
use Pulsar\Auth\Authorization\Event\AuthorizationDenied;
use Pulsar\Auth\Authorization\Event\AuthorizationGranted;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;

use function array_any;
use function in_array;

/**
 * Authorization gate combining RBAC and ABAC strategies.
 *
 * Evaluation order:
 * 1. Super-role bypass (configurable roles that skip all checks)
 * 2. ABAC policies: explicit deny short-circuits immediately
 * 3. RBAC: role→permission check via RoleRegistry
 * 4. ABAC policies: explicit allow can grant access without RBAC match
 * 5. Default: deny
 *
 * When an EventDispatcherInterface is provided, dispatches AuthorizationGranted
 * and AuthorizationDenied events as envelopes for audit trail purposes.
 */
final class Gate implements GateInterface
{
    /** @var list<PolicyInterface> */
    private array $policies = [];

    /**
     * @param list<string> $superRoles Roles that bypass all permission checks
     */
    public function __construct(
        private readonly RoleRegistryInterface $roleRegistry,
        private readonly array $superRoles = [],
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    /**
     * Register an ABAC policy.
     */
    public function addPolicy(PolicyInterface $policy): void
    {
        $this->policies[] = $policy;
    }

    #[Override]
    public function allows(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        // 1. Super-role bypass
        if ($this->hasSuperRole($identity)) {
            $this->dispatchGranted($identity, $permission, $context?->resource, 'super-role');
            return true;
        }

        $context ??= new PolicyContext(permission: $permission);

        // Walk the policy list exactly once and capture every verdict:
        // evaluating N policies separately for explicit-deny and for
        // explicit-allow would cost 2N evaluations. An explicit deny
        // short-circuits immediately, but an explicit allow is only
        // remembered until after the RBAC check runs, so RBAC stays
        // the primary grant path.
        $explicitAllow = false;
        foreach ($this->policies as $policy) {
            $result = $policy->evaluate($identity, $context);

            if ($result === false) {
                $this->dispatchDenied($identity, $permission, $context->resource, 'ABAC-deny');
                return false;
            }

            if ($result === true) {
                $explicitAllow = true;
            }
        }

        // 3. RBAC: check role→permission
        $permissions = $this->roleRegistry->permissionsForRoles($identity->roles());

        $rbacAllowed = array_any(
            $permissions,
            static fn(Permission $p): bool => $p->matches($permission),
        );

        if ($rbacAllowed) {
            $this->dispatchGranted($identity, $permission, $context->resource, 'RBAC');
            return true;
        }

        // 4. ABAC explicit allow (granted without RBAC match).
        if ($explicitAllow) {
            $this->dispatchGranted($identity, $permission, $context->resource, 'ABAC');
            return true;
        }

        // 5. Default: deny
        $this->dispatchDenied($identity, $permission, $context->resource, 'default-deny');
        return false;
    }

    #[Override]
    public function denies(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        return !$this->allows($identity, $permission, $context);
    }

    private function hasSuperRole(IdentityInterface $identity): bool
    {
        return array_any(
            $identity->roles(),
            fn(string $role): bool => in_array($role, $this->superRoles, true),
        );
    }

    private function dispatchGranted(
        IdentityInterface $identity,
        string $permission,
        ?string $resource,
        string $grantReason,
    ): void {
        if ($this->eventDispatcher === null) {
            return;
        }

        $correlationId = bin2hex(random_bytes(16));

        $event = AuthorizationGranted::create(
            identityId: $identity->id(),
            permission: $permission,
            resource: $resource,
            grantReason: $grantReason,
            correlationId: $correlationId,
        );

        $envelope = EventEnvelope::wrap(
            eventType: AuthorizationGranted::class,
            schemaVersion: AuthorizationGranted::SCHEMA_VERSION,
            payload: $event->toArray(),
            metadata: new EventMetadata(
                correlationId: CorrelationId::fromString($correlationId),
                causationId: CausationId::fromString($correlationId),
                actor: $identity->id(),
            ),
        );

        $this->eventDispatcher->dispatchEnvelope($envelope);
    }

    private function dispatchDenied(
        IdentityInterface $identity,
        string $permission,
        ?string $resource,
        string $denialReason,
    ): void {
        if ($this->eventDispatcher === null) {
            return;
        }

        $correlationId = bin2hex(random_bytes(16));

        $event = AuthorizationDenied::create(
            identityId: $identity->id(),
            permission: $permission,
            resource: $resource,
            denialReason: $denialReason,
            correlationId: $correlationId,
        );

        $envelope = EventEnvelope::wrap(
            eventType: AuthorizationDenied::class,
            schemaVersion: AuthorizationDenied::SCHEMA_VERSION,
            payload: $event->toArray(),
            metadata: new EventMetadata(
                correlationId: CorrelationId::fromString($correlationId),
                causationId: CausationId::fromString($correlationId),
                actor: $identity->id(),
            ),
        );

        $this->eventDispatcher->dispatchEnvelope($envelope);
    }
}
