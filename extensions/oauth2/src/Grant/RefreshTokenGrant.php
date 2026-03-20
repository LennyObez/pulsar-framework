<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Grant;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\OAuth2\Client\OAuthClient;
use Pulsar\Extension\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\OAuth2\Contract\RefreshTokenRepositoryInterface;
use Pulsar\Extension\OAuth2\Exception\OAuth2Exception;
use Pulsar\Extension\OAuth2\Token\AccessToken;
use Pulsar\Extension\OAuth2\Token\InMemoryRefreshTokenRepository;
use Pulsar\Extension\OAuth2\Token\RefreshToken;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function bin2hex;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function random_bytes;

/**
 * Refresh Token grant handler (RFC 6749 Section 6).
 *
 * Implements token rotation: each use of a refresh token issues a new
 * access token AND a new refresh token, consuming the old one.
 *
 * Replay detection: if a previously consumed (rotated-out) refresh token
 * is reused, the entire token family is revoked and a security event is emitted.
 */
#[Internal(reason: 'Grant handler implementation; use via AuthorizationServerInterface')]
final readonly class RefreshTokenGrant implements GrantInterface
{
    private const int ACCESS_TOKEN_TTL = 3600;
    private const int REFRESH_TOKEN_TTL = 86400 * 30;

    public function __construct(
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private AuditLoggerInterface $auditLogger,
    ) {}

    public function identifier(): string
    {
        return 'refresh_token';
    }

    public function handleTokenRequest(ServerRequestInterface $request, OAuthClient $client): TokenResponse
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $refreshTokenValue = $body['refresh_token'] ?? null;
        if (!is_string($refreshTokenValue) || $refreshTokenValue === '') {
            throw OAuth2Exception::invalidRequest('Missing required parameter: refresh_token');
        }

        // Consume the refresh token (one-time use with replay detection)
        $oldRefreshToken = $this->refreshTokenRepository->consume($refreshTokenValue);

        if ($oldRefreshToken === null) {
            // Check if replay was detected (only available on in-memory implementation)
            if ($this->refreshTokenRepository instanceof InMemoryRefreshTokenRepository
                && $this->refreshTokenRepository->wasReplayDetected()) {
                $this->auditLogger->log(
                    event: AuditEvent::SecurityEvent,
                    outcome: AuditOutcome::Failure,
                    actor: null,
                    action: 'oauth2.refresh_token.replay_detected',
                    resource: 'client:' . $client->id,
                    metadata: ['detail' => 'Rotated-out refresh token reused; entire family revoked'],
                );
            }

            throw OAuth2Exception::invalidGrant('Refresh token is invalid, expired, or already used');
        }

        // Validate client binding
        if ($oldRefreshToken->clientId !== $client->id) {
            throw OAuth2Exception::invalidGrant('Refresh token was not issued to this client');
        }

        // Parse requested scopes (may narrow, but not expand)
        $scopeString = $body['scope'] ?? '';
        $requestedScopeIds = $scopeString !== '' ? explode(' ', (string) $scopeString) : $oldRefreshToken->scopes;

        // Ensure requested scopes are a subset of the original grant
        foreach ($requestedScopeIds as $scopeId) {
            if (!in_array($scopeId, $oldRefreshToken->scopes, true)) {
                throw OAuth2Exception::invalidScope(
                    "Scope '$scopeId' was not included in the original grant",
                );
            }
        }

        // Issue new token pair (rotation)
        $now = new DateTimeImmutable();
        $newAccessTokenValue = bin2hex(random_bytes(32));
        $newRefreshTokenValue = bin2hex(random_bytes(32));

        $accessToken = new AccessToken(
            id: bin2hex(random_bytes(16)),
            clientId: $client->id,
            subjectId: $oldRefreshToken->subjectId,
            scopes: $requestedScopeIds,
            expiresAt: $now->modify('+' . self::ACCESS_TOKEN_TTL . ' seconds'),
            issuedAt: $now,
            tokenValue: $newAccessTokenValue,
        );

        $newRefreshToken = new RefreshToken(
            id: bin2hex(random_bytes(16)),
            clientId: $client->id,
            subjectId: $oldRefreshToken->subjectId,
            sessionId: $oldRefreshToken->sessionId,
            familyId: $oldRefreshToken->familyId,
            scopes: $requestedScopeIds,
            expiresAt: $now->modify('+' . self::REFRESH_TOKEN_TTL . ' seconds'),
            issuedAt: $now,
            tokenValue: $newRefreshTokenValue,
        );

        $this->accessTokenRepository->persist($accessToken);
        $this->refreshTokenRepository->persist($newRefreshToken);

        $this->auditLogger->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: $oldRefreshToken->subjectId,
            action: 'oauth2.token.refreshed',
            resource: 'client:' . $client->id,
            metadata: [
                'grant_type' => 'refresh_token',
                'family_id' => $oldRefreshToken->familyId,
                'scopes' => $requestedScopeIds,
            ],
        );

        return new TokenResponse(
            accessToken: $newAccessTokenValue,
            tokenType: 'Bearer',
            expiresIn: self::ACCESS_TOKEN_TTL,
            scopes: $requestedScopeIds,
            refreshToken: $newRefreshTokenValue,
        );
    }
}
