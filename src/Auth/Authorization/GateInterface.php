<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use Pulsar\Auth\Identity\IdentityInterface;

/**
 * Contract for the authorization gate.
 *
 * The gate combines RBAC (roles/permissions) with ABAC (policies)
 * to make authorization decisions.
 */
interface GateInterface
{
    /**
     * Check if the identity is allowed the given permission.
     */
    public function allows(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool;

    /**
     * Check if the identity is denied the given permission.
     */
    public function denies(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool;
}
