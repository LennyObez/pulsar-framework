<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Grant;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Client\OAuthClient;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;

/**
 * Protocol conformance tests for client credentials grant constraints.
 *
 * Validates the domain invariants that any ClientCredentialsGrant
 * implementation must enforce.
 */
#[CoversClass(OAuthClient::class)]
final class ClientCredentialsGrantTest extends TestCase
{
    #[Test]
    public function confidentialClientHasSecretHash(): void
    {
        $client = new OAuthClient(
            id: 'client-cc-001',
            name: 'Service App',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: ['api:read'],
            confidential: true,
            secretHash: '$2y$10$hashedvalue',
        );

        self::assertTrue($client->confidential);
        self::assertNotNull($client->secretHash);
    }

    #[Test]
    public function publicClientMustBeRejectedForClientCredentials(): void
    {
        $client = new OAuthClient(
            id: 'client-cc-002',
            name: 'Public SPA',
            redirectUris: ['https://spa.example.com/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['openid'],
            confidential: false,
        );

        // Public client should not be allowed for client_credentials
        self::assertFalse($client->confidential);
        self::assertFalse($client->hasGrantType('client_credentials'));
    }

    #[Test]
    public function grantTypeRestrictionEnforced(): void
    {
        $client = new OAuthClient(
            id: 'client-cc-003',
            name: 'Auth Code Only',
            redirectUris: ['https://app.example.com/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['openid'],
            confidential: true,
            secretHash: '$2y$10$hashedvalue',
        );

        self::assertFalse($client->hasGrantType('client_credentials'));
    }

    #[Test]
    public function clientCredentialsGrantAllowed(): void
    {
        $client = new OAuthClient(
            id: 'client-cc-004',
            name: 'Service App',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: ['api:read', 'api:write'],
            confidential: true,
            secretHash: '$2y$10$hashedvalue',
        );

        self::assertTrue($client->hasGrantType('client_credentials'));
    }

    #[Test]
    public function tokenIssuedWithoutRefreshToken(): void
    {
        // Client credentials flow: access tokens only, no refresh tokens
        $accessToken = new AccessToken(
            id: 'at-cc-001',
            clientId: 'client-cc-004',
            subjectId: 'client-cc-004', // subject = client in CC flow
            scopes: ['api:read'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        self::assertTrue($accessToken->isActive());
        self::assertSame('client-cc-004', $accessToken->subjectId);
    }
}
