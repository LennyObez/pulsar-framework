<?php

declare(strict_types=1);

namespace Pulsar\Auth\Guard;

use Pulsar\Api\Api;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Request;

/**
 * Contract for authentication guards.
 *
 * A guard extracts credentials from a request and resolves them to an identity.
 */
#[Api]
interface GuardInterface
{
    /**
     * Attempt to authenticate the request.
     *
     * Returns the resolved identity, or null if this guard cannot authenticate the request.
     */
    public function authenticate(Request $request): ?IdentityInterface;

    /**
     * Get the unique name of this guard.
     */
    public function name(): string;
}
