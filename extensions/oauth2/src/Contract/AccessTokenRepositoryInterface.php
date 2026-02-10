<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\OAuth2\Token\AccessToken;

/**
 * Repository for access token storage and introspection.
 *
 * For reference tokens: stored hashed, supports introspection and revocation.
 * For JWT tokens: not stored (self-contained), revocation via refresh token revocation.
 */
#[Api(since: '1.0.0')]
interface AccessTokenRepositoryInterface
{
    /**
     * Persist a new access token.
     *
     * For reference tokens, the token value is hashed before storage.
     * For JWT tokens, this is a no-op (self-contained tokens are not stored).
     */
    public function persist(AccessToken $token): void;

    /**
     * Introspect an access token.
     *
     * Returns the token metadata if the token is active, null if revoked or expired.
     *
     * @param string $tokenValue The raw token value (reference token) or JWT string
     */
    public function introspect(string $tokenValue): ?AccessToken;

    /**
     * Revoke an access token.
     */
    public function revoke(string $tokenId): void;

    /**
     * Revoke all access tokens for a subject.
     */
    public function revokeBySubject(string $subjectId): void;

    /**
     * Check if an access token has been revoked.
     */
    public function isRevoked(string $tokenId): bool;
}
