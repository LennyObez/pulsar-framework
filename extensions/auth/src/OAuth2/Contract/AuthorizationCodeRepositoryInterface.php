<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\OAuth2\Token\AuthorizationCode;

/**
 * Repository for authorization code lifecycle management.
 *
 * Authorization codes are one-time use, short-lived (default 10 min),
 * bound to client + redirect_uri + PKCE verifier, and stored hashed.
 * @api
 */
#[Api(since: '1.0.0')]
interface AuthorizationCodeRepositoryInterface
{
    /**
     * Persist a new authorization code.
     *
     * The code value is hashed before storage. Never stored in plaintext.
     */
    public function persist(AuthorizationCode $code): void;

    /**
     * Consume an authorization code (one-time use).
     *
     * Returns the code if valid and not yet consumed, null otherwise.
     * Marks the code as consumed atomically to prevent replay.
     *
     * @param string $codeValue The raw authorization code value
     */
    public function consume(string $codeValue): ?AuthorizationCode;

    /**
     * Revoke an authorization code.
     */
    public function revoke(string $codeId): void;

    /**
     * Check if an authorization code has been revoked or consumed.
     */
    public function isRevoked(string $codeId): bool;
}
