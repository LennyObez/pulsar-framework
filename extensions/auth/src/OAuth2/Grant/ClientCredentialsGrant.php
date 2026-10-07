<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Grant;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Auth\OAuth2\Client\OAuthClient;
use Pulsar\Extension\Auth\OAuth2\Config\OAuth2Config;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\ScopeRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Exception\OAuth2Exception;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function bin2hex;
use function explode;
use function is_array;
use function is_string;
use function random_bytes;

/**
 * Client Credentials grant handler (RFC 6749 Section 4.4).
 *
 * Only confidential clients may use this grant. Issues access tokens only
 * (no refresh tokens). The client acts on its own behalf, not on behalf of a user.
 */
#[Internal(reason: 'Grant handler implementation; use via AuthorizationServerInterface')]
final readonly class ClientCredentialsGrant implements GrantInterface
{
    public function __construct(
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private ScopeRepositoryInterface $scopeRepository,
        private AuditLoggerInterface $auditLogger,
        private OAuth2Config $config = new OAuth2Config(),
    ) {}

    public function identifier(): string
    {
        return 'client_credentials';
    }

    public function handleTokenRequest(ServerRequestInterface $request, OAuthClient $client): TokenResponse
    {
        // Client credentials grant is only for confidential clients
        if (!$client->confidential) {
            throw OAuth2Exception::unauthorizedClient(
                'Only confidential clients may use the client_credentials grant',
            );
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        // Parse requested scopes
        /** @var string $scopeString */
        $scopeString = isset($body['scope']) && is_string($body['scope']) ? $body['scope'] : '';
        $requestedScopeIds = $scopeString !== '' ? explode(' ', $scopeString) : [];

        // Resolve and validate scopes
        $resolvedScopes = $this->scopeRepository->resolveScopes(
            $requestedScopeIds,
            'client_credentials',
            $client->id,
        );

        $scopeIds = [];
        foreach ($resolvedScopes as $scope) {
            $scopeIds[] = $scope->id;
        }

        // Issue access token only (no refresh token for client_credentials)
        $now = new DateTimeImmutable();
        $tokenValue = bin2hex(random_bytes(32));

        $accessTokenTtl = $this->config->accessTokenTtl;

        $accessToken = new AccessToken(
            id: bin2hex(random_bytes(16)),
            clientId: $client->id,
            subjectId: $client->id,
            scopes: $scopeIds,
            expiresAt: $now->modify('+' . $accessTokenTtl . ' seconds'),
            issuedAt: $now,
            tokenValue: $tokenValue,
        );

        $this->accessTokenRepository->persist($accessToken);

        $this->auditLogger->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: $client->id,
            action: 'oauth2.token.issued',
            resource: 'client:' . $client->id,
            metadata: [
                'grant_type' => 'client_credentials',
                'scopes' => $scopeIds,
            ],
        );

        return new TokenResponse(
            accessToken: $tokenValue,
            tokenType: 'Bearer',
            expiresIn: $accessTokenTtl,
            scopes: $scopeIds,
        );
    }
}
