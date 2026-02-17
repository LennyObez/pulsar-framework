<?php

declare(strict_types=1);

namespace Pulsar\Auth\Guard;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Auth\Identity\IdentityInterface;

/**
 * Contract for authentication guards.
 *
 * A guard extracts credentials from a request and resolves them to an identity.
 */
#[Api(since: '1.0.0')]
interface GuardInterface
{
    /**
     * Attempt to authenticate the request.
     *
     * Returns the resolved identity, or null if this guard cannot authenticate the request.
     */
    public function authenticate(ServerRequestInterface $request): ?IdentityInterface;

    /**
     * Get the unique name of this guard.
     */
    public function name(): string;
}
