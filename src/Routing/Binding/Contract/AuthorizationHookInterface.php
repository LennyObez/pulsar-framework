<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding\Contract;

use Pulsar\Api\Api;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Routing\Binding\BindingMeta;

/**
 * Hook for authorizing access to resolved route-bound models.
 *
 * Called by the model-binding middleware after a model is resolved
 * but before the controller receives it. Implementations delegate
 * to the authorization gate or a custom policy strategy.
 */
#[Api(since: '1.0.0-rc.11')]
interface AuthorizationHookInterface
{
    /**
     * Check authorization for a resolved model.
     *
     * Returns true if authorized, false if denied.
     */
    public function authorize(IdentityInterface $identity, object $model, BindingMeta $meta): bool;
}
