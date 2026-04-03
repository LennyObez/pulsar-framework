<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Adapter;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Auth\OAuth2\Client\OAuthClient;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\AuthorizationServerInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\ClientRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\ConsentRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\RefreshTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\ScopeRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Exception\OAuth2Exception;
use Pulsar\Extension\Auth\OAuth2\Grant\AuthorizationCodeGrant;
use Pulsar\Extension\Auth\OAuth2\Grant\GrantInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function array_map;
use function base64_decode;
use function explode;
use function http_build_query;
use function is_array;
use function is_string;
use function json_encode;
use function str_contains;
use function substr;

/**
 * OAuth2 authorization server implementation.
 *
 * Handles the authorization, token, introspection, and revocation endpoints
 * per RFC 6749, RFC 7662, and RFC 7009.
 *
 * Note on naming (F385.11): the class was previously called
 * `LeagueAuthorizationServer` despite never importing `league/oauth2-server`.
 * The "League" prefix gave reviewers and auditors the impression that the
 * implementation was a thin adapter over the battle-tested upstream library
 * - it is in fact a homegrown implementation. ADR-0025 mandates the
 * `league/oauth2-server` swap before 1.0.0 GA; until that refactor lands
 * the class keeps the neutral name `OAuth2AuthorizationServer` so its
 * homegrown status is not obscured.
 */
#[Internal(reason: 'Adapter implementation; use AuthorizationServerInterface contract')]
final readonly class OAuth2AuthorizationServer implements AuthorizationServerInterface
{
    /** @var array<string, GrantInterface> */
    private array $grants;

    /**
     * @param list<GrantInterface> $grants
     */
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private ScopeRepositoryInterface $scopeRepository,
        private ConsentRepositoryInterface $consentRepository,
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private AuthorizationCodeGrant $authorizationCodeGrant,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private AuditLoggerInterface $auditLogger,
        array $grants = [],
    ) {
        $indexed = [];
        foreach ($grants as $grant) {
            $indexed[$grant->identifier()] = $grant;
        }
        // Always include the authorization_code grant
        $indexed[$this->authorizationCodeGrant->identifier()] = $this->authorizationCodeGrant;
        $this->grants = $indexed;
    }

    public function handleAuthorizationRequest(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->processAuthorizationRequest($request);
        } catch (OAuth2Exception $e) {
            return $this->buildErrorRedirectOrJson($request, $e);
        }
    }

    public function handleTokenRequest(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->processTokenRequest($request);
        } catch (OAuth2Exception $e) {
            return $this->jsonResponse($e->toErrorResponse(), $e->httpStatusCode());
        }
    }

    public function handleIntrospectionRequest(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->processIntrospectionRequest($request);
        } catch (OAuth2Exception $e) {
            return $this->jsonResponse($e->toErrorResponse(), $e->httpStatusCode());
        }
    }

    public function handleRevocationRequest(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->processRevocationRequest($request);
        } catch (OAuth2Exception $e) {
            // RFC 7009: The authorization server responds with HTTP status 200 even for errors
            // to prevent token scanning, except for client authentication failures
            if ($e->errorCode() === 'invalid_client') {
                return $this->jsonResponse($e->toErrorResponse(), $e->httpStatusCode());
            }

            return $this->jsonResponse([]);
        }
    }

    private function processAuthorizationRequest(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $params */
        $params = $request->getQueryParams();

        $clientId = $this->extractRequiredQueryParam($params, 'client_id');
        $redirectUri = $this->extractRequiredQueryParam($params, 'redirect_uri');
        $responseType = $this->extractRequiredQueryParam($params, 'response_type');
        $codeChallenge = $this->extractRequiredQueryParam($params, 'code_challenge');
        $codeChallengeMethod = $params['code_challenge_method'] ?? 'S256';
        $state = $params['state'] ?? null;
        $nonce = $params['nonce'] ?? null;

        if ($responseType !== 'code') {
            throw OAuth2Exception::invalidRequest('Only response_type=code is supported');
        }

        if ($codeChallengeMethod !== 'S256') {
            throw OAuth2Exception::invalidRequest('Only S256 code_challenge_method is supported');
        }

        // Validate client
        $client = $this->clientRepository->findById($clientId);
        if ($client === null) {
            throw OAuth2Exception::invalidClient('Unknown client');
        }

        if (!$client->hasGrantType('authorization_code')) {
            throw OAuth2Exception::unauthorizedClient('Client is not authorized for authorization_code grant');
        }

        // Validate redirect URI (strict exact match)
        if (!$client->hasRedirectUri($redirectUri)) {
            throw OAuth2Exception::invalidRequest('Invalid redirect_uri');
        }

        // Parse and resolve scopes
        /** @var string $scopeString */
        $scopeString = isset($params['scope']) && is_string($params['scope']) ? $params['scope'] : '';
        $requestedScopeIds = $scopeString !== '' ? explode(' ', $scopeString) : [];
        $resolvedScopes = $this->scopeRepository->resolveScopes($requestedScopeIds, 'authorization_code', $clientId);
        $scopeIds = array_map(static fn($s) => $s->id, $resolvedScopes);

        // Check for existing consent (auto-approve if already consented)
        $subjectId = $this->extractSubjectFromRequest($request);

        if ($subjectId === null) {
            // No authenticated user; return 401
            throw OAuth2Exception::accessDenied('User authentication required');
        }

        if (!$this->consentRepository->hasConsent($subjectId, $clientId, $scopeIds)) {
            // Consent required — deny by default, delegate the UX to the
            // application layer. The authorization-server adapter is
            // protocol-focused and intentionally does not render HTML.
            // The consent screen is implemented by application middleware
            // that catches `OAuth2Exception::accessDenied('User consent
            // required')`, renders the approval UI, and on submission
            // stores the approval via `ConsentRepository::grant()` before
            // re-dispatching the original authorization request.
            throw OAuth2Exception::accessDenied('User consent required');
        }

        // Issue authorization code
        $authCode = $this->authorizationCodeGrant->createAuthorizationCode(
            client: $client,
            subjectId: $subjectId,
            redirectUri: $redirectUri,
            scopes: $scopeIds,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod,
            nonce: is_string($nonce) ? $nonce : null,
        );

        // Redirect back to client with authorization code
        $redirectParams = ['code' => $authCode->codeValue];
        if (is_string($state) && $state !== '') {
            $redirectParams['state'] = $state;
        }

        $redirectTarget = $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($redirectParams);

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $redirectTarget)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function processTokenRequest(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $grantType = $body['grant_type'] ?? null;
        if (!is_string($grantType) || $grantType === '') {
            throw OAuth2Exception::invalidRequest('Missing required parameter: grant_type');
        }

        // Authenticate client
        $client = $this->authenticateClient($request, $grantType);

        // Find the grant handler
        $grant = $this->grants[$grantType] ?? null;
        if ($grant === null) {
            throw OAuth2Exception::unsupportedGrantType($grantType);
        }

        // Validate client is authorized for this grant type
        if (!$client->hasGrantType($grantType)) {
            throw OAuth2Exception::unauthorizedClient(
                "Client is not authorized for the '$grantType' grant type",
            );
        }

        $tokenResponse = $grant->handleTokenRequest($request, $client);

        return $this->jsonResponse($tokenResponse->toArray(), 200, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    private function processIntrospectionRequest(ServerRequestInterface $request): ResponseInterface
    {
        // Authenticate the requesting client (resource server)
        $this->authenticateClient($request, 'introspection');

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $token = $body['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw OAuth2Exception::invalidRequest('Missing required parameter: token');
        }

        // Try to introspect as access token
        $accessToken = $this->accessTokenRepository->introspect($token);

        if ($accessToken !== null && $accessToken->isActive()) {
            return $this->jsonResponse([
                'active' => true,
                'scope' => implode(' ', $accessToken->scopes),
                'client_id' => $accessToken->clientId,
                'sub' => $accessToken->subjectId,
                'exp' => $accessToken->expiresAt->getTimestamp(),
                'iat' => $accessToken->issuedAt->getTimestamp(),
                'token_type' => 'Bearer',
            ]);
        }

        // Token is not active or not found
        return $this->jsonResponse(['active' => false]);

    }

    private function processRevocationRequest(ServerRequestInterface $request): ResponseInterface
    {
        // Authenticate the requesting client
        $client = $this->authenticateClient($request, 'revocation');

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $token = $body['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw OAuth2Exception::invalidRequest('Missing required parameter: token');
        }

        $tokenTypeHint = $body['token_type_hint'] ?? null;

        // Try to revoke based on hint or try both types
        $revoked = false;

        if ($tokenTypeHint === 'refresh_token' || $tokenTypeHint === null) {
            $revoked = $this->tryRevokeRefreshToken($token, $client);
        }

        if (!$revoked && ($tokenTypeHint === 'access_token' || $tokenTypeHint === null)) {
            $revoked = $this->tryRevokeAccessToken($token, $client);
        }

        if ($revoked) {
            $this->auditLogger->log(
                event: AuditEvent::Authentication,
                outcome: AuditOutcome::Success,
                actor: $client->id,
                action: 'oauth2.token.revoked',
                resource: 'client:' . $client->id,
                metadata: ['token_type_hint' => $tokenTypeHint],
            );
        }

        // RFC 7009: Always return 200, even if token was not found
        return $this->jsonResponse([]);
    }

    /**
     * Authenticate client from the request (Basic auth or body params).
     */
    private function authenticateClient(ServerRequestInterface $request, string $grantType): OAuthClient
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $clientId = null;
        $clientSecret = null;

        // Try HTTP Basic authentication first
        $authHeader = $request->getHeaderLine('Authorization');
        if (str_starts_with($authHeader, 'Basic ')) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                $parts = explode(':', $decoded, 2);
                $clientId = $parts[0];
                $clientSecret = $parts[1] ?? '';
            }
        }

        // Fall back to body parameters
        if ($clientId === null) {
            $clientId = $body['client_id'] ?? null;
            $clientSecret = $body['client_secret'] ?? null;

            if (!is_string($clientId) || $clientId === '') {
                $this->auditLogger->log(
                    event: AuditEvent::Authentication,
                    outcome: AuditOutcome::Failure,
                    actor: AuditActor::anonymous(),
                    action: 'oauth2.client.authentication_failed',
                    resource: 'oauth2.client',
                    metadata: ['reason' => 'Missing client_id'],
                );

                throw OAuth2Exception::invalidClient('Missing client credentials');
            }

            $clientSecret = is_string($clientSecret) && $clientSecret !== '' ? $clientSecret : null;
        }

        $client = $this->clientRepository->findById($clientId);

        if ($client === null) {
            $this->auditLogger->log(
                event: AuditEvent::Authentication,
                outcome: AuditOutcome::Failure,
                actor: $clientId,
                action: 'oauth2.client.authentication_failed',
                resource: 'client:' . $clientId,
                metadata: ['reason' => 'Unknown client'],
            );

            throw OAuth2Exception::invalidClient();
        }

        if (!$this->clientRepository->validateClient($clientId, $clientSecret, $grantType)) {
            $this->auditLogger->log(
                event: AuditEvent::Authentication,
                outcome: AuditOutcome::Failure,
                actor: $clientId,
                action: 'oauth2.client.authentication_failed',
                resource: 'client:' . $clientId,
                metadata: ['reason' => 'Invalid credentials or unauthorized grant type'],
            );

            throw OAuth2Exception::invalidClient();
        }

        return $client;
    }

    /**
     * Try to revoke a refresh token by its raw value.
     *
     * Consumes the token (marking it one-time-used), then revokes it by ID.
     * If the token was already consumed or revoked, consume() returns null
     * and we cannot identify the token: this is fine per RFC 7009 (best-effort).
     */
    private function tryRevokeRefreshToken(string $tokenValue, OAuthClient $client): bool
    {
        $refreshToken = $this->refreshTokenRepository->consume($tokenValue);

        if ($refreshToken === null) {
            return false;
        }

        // Only the client that was issued the token can revoke it
        if ($refreshToken->clientId !== $client->id) {
            return false;
        }

        // Revoke the token and its entire family
        $this->refreshTokenRepository->revokeFamily($refreshToken->familyId);

        return true;
    }

    /**
     * Try to revoke an access token by its raw value.
     */
    private function tryRevokeAccessToken(string $tokenValue, OAuthClient $client): bool
    {
        $accessToken = $this->accessTokenRepository->introspect($tokenValue);

        if ($accessToken === null) {
            return false;
        }

        // Only the client that was issued the token can revoke it
        if ($accessToken->clientId !== $client->id) {
            return false;
        }

        $this->accessTokenRepository->revoke($accessToken->id);

        return true;
    }

    /**
     * Extract the authenticated subject (user) from the request.
     *
     * This looks for a 'subject_id' attribute set by authentication middleware.
     */
    private function extractSubjectFromRequest(ServerRequestInterface $request): ?string
    {
        $subject = $request->getAttribute('subject_id');

        return is_string($subject) ? $subject : null;
    }

    /**
     * Build an error redirect if we have a valid redirect URI, otherwise return JSON error.
     */
    private function buildErrorRedirectOrJson(ServerRequestInterface $request, OAuth2Exception $e): ResponseInterface
    {
        $params = $request->getQueryParams();
        $redirectUri = $params['redirect_uri'] ?? null;
        $clientId = $params['client_id'] ?? null;
        $state = $params['state'] ?? null;

        // Only redirect if we have a valid redirect URI registered for this client
        if (is_string($redirectUri) && is_string($clientId)) {
            $client = $this->clientRepository->findById($clientId);
            if ($client !== null && $client->hasRedirectUri($redirectUri)) {
                $errorParams = [
                    'error' => $e->errorCode(),
                    'error_description' => $e->getMessage(),
                ];

                if (is_string($state) && $state !== '') {
                    $errorParams['state'] = $state;
                }

                $redirectTarget = $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($errorParams);

                return $this->responseFactory->createResponse(302)
                    ->withHeader('Location', $redirectTarget)
                    ->withHeader('Cache-Control', 'no-store')
                    ->withHeader('Pragma', 'no-cache');
            }
        }

        return $this->jsonResponse($e->toErrorResponse(), $e->httpStatusCode());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function extractRequiredQueryParam(array $params, string $name): string
    {
        $value = $params[$name] ?? null;

        if (!is_string($value) || $value === '') {
            throw OAuth2Exception::invalidRequest("Missing required parameter: $name");
        }

        return $value;
    }

    /**
     * Build a JSON response.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function jsonResponse(array $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $body = $data !== [] ? json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : '{}';

        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json;charset=UTF-8')
            ->withBody($this->streamFactory->createStream($body));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
