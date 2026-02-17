<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\OAuth2\Token\RefreshToken;

/**
 * Repository for refresh token storage and revocation.
 *
 * Refresh tokens are hashed in storage (never plaintext), support rotation
 * with one-time use policy, and include replay detection that revokes the
 * entire token family on reuse of a rotated-out token.
 */
#[Api(since: '1.0.0')]
interface RefreshTokenRepositoryInterface
{
    /**
     * Persist a new refresh token.
     *
     * The token value is hashed before storage.
     */
    public function persist(RefreshToken $token): void;

    /**
     * Consume a refresh token for rotation.
     *
     * Returns the token if valid and not yet consumed, null otherwise.
     * Marks the token as consumed atomically.
     *
     * If a previously rotated-out token from the same family is reused,
     * this triggers family revocation (breach indicator) and returns null.
     *
     * @param string $tokenValue The raw refresh token value
     */
    public function consume(string $tokenValue): ?RefreshToken;

    /**
     * Revoke a specific refresh token.
     */
    public function revoke(string $tokenId): void;

    /**
     * Revoke all refresh tokens in a token family.
     *
     * Called when replay is detected (a rotated-out token is reused).
     * This is a breach indicator: the entire token chain is compromised.
     */
    public function revokeFamily(string $familyId): void;

    /**
     * Revoke all refresh tokens for a subject.
     */
    public function revokeBySubject(string $subjectId): void;

    /**
     * Check if a refresh token has been revoked.
     */
    public function isRevoked(string $tokenId): bool;
}
