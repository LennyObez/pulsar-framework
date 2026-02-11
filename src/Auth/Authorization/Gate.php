<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use Override;
use Pulsar\Auth\Identity\IdentityInterface;

use function array_any;
use function in_array;

/**
 * Authorization gate combining RBAC and ABAC strategies.
 *
 * Evaluation order:
 * 1. Super-role bypass (configurable roles that skip all checks)
 * 2. ABAC policies — explicit deny short-circuits immediately
 * 3. RBAC — role→permission check via RoleRegistry
 * 4. ABAC policies — explicit allow can grant access without RBAC match
 * 5. Default: deny
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
            return true;
        }

        $context ??= new PolicyContext(permission: $permission);

        // 2. ABAC: check for explicit deny
        foreach ($this->policies as $policy) {
            $result = $policy->evaluate($identity, $context);

            if ($result === false) {
                return false;
            }
        }

        // 3. RBAC: check role→permission
        $permissions = $this->roleRegistry->permissionsForRoles($identity->roles());

        $rbacAllowed = array_any(
            $permissions,
            static fn(Permission $p): bool => $p->matches($permission),
        );

        if ($rbacAllowed) {
            return true;
        }

        // 4. ABAC: check for explicit allow (can grant without RBAC)
        foreach ($this->policies as $policy) {
            $result = $policy->evaluate($identity, $context);

            if ($result === true) {
                return true;
            }
        }

        // 5. Default: deny
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
}
