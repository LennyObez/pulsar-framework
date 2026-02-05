<?php

declare(strict_types=1);

namespace Pulsar\Auth\Guard;

use Pulsar\Api\Api;
use Pulsar\Auth\Identity\IdentityInterface;

/**
 * Contract for resolving a bearer token to an identity.
 *
 * Applications or extensions implement this interface and register it
 * in the container to enable token-based authentication.
 */
#[Api]
interface TokenResolverInterface
{
    /**
     * Resolve a bearer token to an identity.
     *
     * Returns null if the token is invalid or expired.
     */
    public function resolve(string $token): ?IdentityInterface;
}
