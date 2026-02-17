<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;
use Pulsar\Extension\Auth\OAuth2\Token\RefreshToken;
use Pulsar\Extension\Auth\OAuth2\Token\TokenPair;

/**
 * Issues access tokens, refresh tokens, and ID tokens.
 *
 * All token signing uses Keyring-managed keys (Finding B).
 * All issuance events are audit-logged (Finding D).
 */
#[Api(since: '1.0.0')]
interface TokenIssuerInterface
{
    /**
     * Issue a new access token.
     *
     * @param string $clientId The OAuth2 client identifier
     * @param string $subjectId The resource owner identifier
     * @param list<string> $scopes Granted scopes
     * @param int|null $ttl Token lifetime in seconds (null = server default)
     */
    public function issueAccessToken(
        string $clientId,
        string $subjectId,
        array $scopes,
        ?int $ttl = null,
    ): AccessToken;

    /**
     * Issue a new refresh token bound to the given access token.
     *
     * Refresh tokens are bound to: client + subject + session.
     * Stored hashed, never in plaintext.
     *
     * @param string $clientId The OAuth2 client identifier
     * @param string $subjectId The resource owner identifier
     * @param string $sessionId The session binding identifier
     * @param list<string> $scopes Granted scopes
     * @param string|null $familyId Token family for rotation tracking (null = new family)
     */
    public function issueRefreshToken(
        string $clientId,
        string $subjectId,
        string $sessionId,
        array $scopes,
        ?string $familyId = null,
    ): RefreshToken;

    /**
     * Issue an access token and refresh token pair.
     *
     * @param string $clientId The OAuth2 client identifier
     * @param string $subjectId The resource owner identifier
     * @param string $sessionId The session binding identifier
     * @param list<string> $scopes Granted scopes
     */
    public function issueTokenPair(
        string $clientId,
        string $subjectId,
        string $sessionId,
        array $scopes,
    ): TokenPair;
}
