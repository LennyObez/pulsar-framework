<?php

declare(strict_types=1);

namespace Pulsar\Dev;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Identity\IdentityInterface;

/**
 * A permissive authorization gate for dev servers.
 *
 * Grants all permissions to authenticated identities. Used in dev
 * routers so controllers that call requireIdentity() succeed without
 * requiring real auth middleware.
 */
#[Internal(reason: 'Dev server implementation detail')]
final class DevPermissiveGate implements GateInterface
{
    #[Override]
    public function allows(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        return $identity->isAuthenticated();
    }

    #[Override]
    public function denies(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        return !$this->allows($identity, $permission, $context);
    }
}
