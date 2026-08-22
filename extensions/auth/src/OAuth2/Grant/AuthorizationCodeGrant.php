<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Grant;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Auth\OAuth2\Client\OAuthClient;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\AuthorizationCodeRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\RefreshTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Exception\OAuth2Exception;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;
use Pulsar\Extension\Auth\OAuth2\Token\AuthorizationCode;
use Pulsar\Extension\Auth\OAuth2\Token\RefreshToken;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function bin2hex;
use function hash;
use function hash_equals;
use function is_array;
use function is_string;
use function random_bytes;
use function rtrim;
use function strtr;

/**
 * Authorization Code grant handler (RFC 6749 Section 4.1).
 *
 * PKCE is mandatory (S256 only). Authorization codes are one-time use
 * with a 10-minute lifetime.
 */
#[Internal(reason: 'Grant handler implementation; use via AuthorizationServerInterface')]
final readonly class AuthorizationCodeGrant implements GrantInterface
{
    private const int ACCESS_TOKEN_TTL = 3600;
    private const int REFRESH_TOKEN_TTL = 86400 * 30;
    private const int CODE_TTL = 600;

    public function __construct(
        private AuthorizationCodeRepositoryInterface $codeRepository,
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private AuditLoggerInterface $auditLogger,
    ) {}

    public function identifier(): string
    {
        return 'authorization_code';
    }

    /**
     * Generate an authorization code for the given parameters.
     *
     * Called by the authorization server after user consent.
     *
     * @param list<string> $scopes
     */
    public function createAuthorizationCode(
        OAuthClient $client,
        string $subjectId,
        string $redirectUri,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        ?string $nonce = null,
    ): AuthorizationCode {
        if ($codeChallengeMethod !== 'S256') {
            throw OAuth2Exception::invalidRequest('Only S256 code_challenge_method is supported');
        }

        if (!$client->hasRedirectUri($redirectUri)) {
            throw OAuth2Exception::invalidRequest('Invalid redirect_uri');
        }

        $codeValue = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable();

        $code = new AuthorizationCode(
            id: bin2hex(random_bytes(16)),
            clientId: $client->id,
            subjectId: $subjectId,
            redirectUri: $redirectUri,
            scopes: $scopes,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod,
            expiresAt: $now->modify('+' . self::CODE_TTL . ' seconds'),
            issuedAt: $now,
            codeValue: $codeValue,
            nonce: $nonce,
        );

        $this->codeRepository->persist($code);

        $this->auditLogger->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: $subjectId,
            action: 'oauth2.authorization_code.issued',
            resource: 'client:' . $client->id,
            metadata: ['scopes' => $scopes],
        );

        return $code;
    }

    public function handleTokenRequest(ServerRequestInterface $request, OAuthClient $client): TokenResponse
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $code = $this->extractRequiredParam($body, 'code');
        $redirectUri = $this->extractRequiredParam($body, 'redirect_uri');
        $codeVerifier = $this->extractRequiredParam($body, 'code_verifier');

        // Consume the authorization code (one-time use)
        $authCode = $this->codeRepository->consume($code);

        if ($authCode === null) {
            throw OAuth2Exception::invalidGrant('Authorization code is invalid, expired, or already used');
        }

        // Validate client binding
        if ($authCode->clientId !== $client->id) {
            throw OAuth2Exception::invalidGrant('Authorization code was not issued to this client');
        }

        // Validate redirect URI binding (exact match)
        if ($authCode->redirectUri !== $redirectUri) {
            throw OAuth2Exception::invalidGrant('redirect_uri does not match the authorization request');
        }

        // Validate PKCE code_verifier against stored code_challenge
        if (!$this->validatePkce($codeVerifier, $authCode->codeChallenge, $authCode->codeChallengeMethod)) {
            throw OAuth2Exception::invalidGrant('PKCE code_verifier is invalid');
        }

        // Issue tokens
        $now = new DateTimeImmutable();
        $accessTokenValue = bin2hex(random_bytes(32));
        $refreshTokenValue = bin2hex(random_bytes(32));
        $sessionId = bin2hex(random_bytes(16));
        $familyId = bin2hex(random_bytes(16));

        $accessToken = new AccessToken(
            id: bin2hex(random_bytes(16)),
            clientId: $client->id,
            subjectId: $authCode->subjectId,
            scopes: $authCode->scopes,
            expiresAt: $now->modify('+' . self::ACCESS_TOKEN_TTL . ' seconds'),
            issuedAt: $now,
            tokenValue: $accessTokenValue,
        );

        $refreshToken = new RefreshToken(
            id: bin2hex(random_bytes(16)),
            clientId: $client->id,
            subjectId: $authCode->subjectId,
            sessionId: $sessionId,
            familyId: $familyId,
            scopes: $authCode->scopes,
            expiresAt: $now->modify('+' . self::REFRESH_TOKEN_TTL . ' seconds'),
            issuedAt: $now,
            tokenValue: $refreshTokenValue,
        );

        $this->accessTokenRepository->persist($accessToken);
        $this->refreshTokenRepository->persist($refreshToken);

        $this->auditLogger->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: $authCode->subjectId,
            action: 'oauth2.token.issued',
            resource: 'client:' . $client->id,
            metadata: [
                'grant_type' => 'authorization_code',
                'scopes' => $authCode->scopes,
            ],
        );

        return new TokenResponse(
            accessToken: $accessTokenValue,
            tokenType: 'Bearer',
            expiresIn: self::ACCESS_TOKEN_TTL,
            scopes: $authCode->scopes,
            refreshToken: $refreshTokenValue,
        );
    }

    /**
     * Validate PKCE code_verifier against the stored code_challenge.
     *
     * S256: BASE64URL(SHA256(code_verifier)) == code_challenge
     */
    private function validatePkce(string $codeVerifier, string $codeChallenge, string $method): bool
    {
        if ($method !== 'S256') {
            return false;
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return hash_equals($codeChallenge, $computed);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function extractRequiredParam(array $body, string $param): string
    {
        $value = $body[$param] ?? null;

        if (!is_string($value) || $value === '') {
            throw OAuth2Exception::invalidRequest("Missing required parameter: $param");
        }

        return $value;
    }
}
