<?php

declare(strict_types=1);

namespace Pulsar\Auth;

use Pulsar\Api\Api;
use Pulsar\Auth\Guard\GuardInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Request;

/**
 * Contract for the authentication manager.
 *
 * Orchestrates multiple guards to resolve an identity from a request.
 */
#[Api(since: '1.0.0')]
interface AuthManagerInterface
{
    /**
     * Authenticate the request by iterating guards in priority order.
     *
     * Returns AnonymousIdentity if no guard can authenticate.
     */
    public function authenticate(Request $request): IdentityInterface;

    /**
     * Get a specific guard by name.
     */
    public function guard(string $name): GuardInterface;

    /**
     * Get the default guard name.
     */
    public function defaultGuard(): string;
}
