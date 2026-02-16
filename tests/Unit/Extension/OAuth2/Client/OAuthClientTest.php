<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Client;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Client\OAuthClient;

#[CoversClass(OAuthClient::class)]
final class OAuthClientTest extends TestCase
{
    #[Test]
    public function constructionPreservesAllFields(): void
    {
        $createdAt = new DateTimeImmutable();

        $client = new OAuthClient(
            id: 'client-1',
            name: 'Test App',
            redirectUris: ['https://app.example.com/callback'],
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: ['openid', 'profile'],
            confidential: true,
            active: true,
            secretHash: '$2y$10$hashvalue',
            createdAt: $createdAt,
        );

        self::assertSame('client-1', $client->id);
        self::assertSame('Test App', $client->name);
        self::assertSame(['https://app.example.com/callback'], $client->redirectUris);
        self::assertSame(['authorization_code', 'refresh_token'], $client->grantTypes);
        self::assertSame(['openid', 'profile'], $client->scopes);
        self::assertTrue($client->confidential);
        self::assertTrue($client->active);
        self::assertSame('$2y$10$hashvalue', $client->secretHash);
        self::assertSame($createdAt, $client->createdAt);
    }

    #[Test]
    public function defaultsAreApplied(): void
    {
        $client = new OAuthClient(
            id: 'client-2',
            name: 'Public App',
            redirectUris: ['https://app.example.com/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['openid'],
            confidential: false,
        );

        self::assertTrue($client->active);
        self::assertNull($client->secretHash);
        self::assertNull($client->createdAt);
    }

    #[Test]
    public function hasRedirectUriMatchesExactly(): void
    {
        $client = new OAuthClient(
            id: 'client-3',
            name: 'Test App',
            redirectUris: [
                'https://app.example.com/callback',
                'https://app.example.com/oauth/callback',
            ],
            grantTypes: ['authorization_code'],
            scopes: ['openid'],
            confidential: true,
        );

        self::assertTrue($client->hasRedirectUri('https://app.example.com/callback'));
        self::assertTrue($client->hasRedirectUri('https://app.example.com/oauth/callback'));
    }

    #[Test]
    public function hasRedirectUriRejectsNonRegisteredUri(): void
    {
        $client = new OAuthClient(
            id: 'client-4',
            name: 'Test App',
            redirectUris: ['https://app.example.com/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['openid'],
            confidential: true,
        );

        self::assertFalse($client->hasRedirectUri('https://evil.example.com/callback'));
    }

    #[Test]
    public function hasRedirectUriDoesNotSupportWildcards(): void
    {
        $client = new OAuthClient(
            id: 'client-5',
            name: 'Test App',
            redirectUris: ['https://app.example.com/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['openid'],
            confidential: true,
        );

        self::assertFalse($client->hasRedirectUri('https://app.example.com/*'));
        self::assertFalse($client->hasRedirectUri('https://app.example.com/callback?extra=1'));
    }

    #[Test]
    public function hasGrantTypeMatchesRegisteredGrant(): void
    {
        $client = new OAuthClient(
            id: 'client-6',
            name: 'Test App',
            redirectUris: [],
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: [],
            confidential: true,
        );

        self::assertTrue($client->hasGrantType('authorization_code'));
        self::assertTrue($client->hasGrantType('refresh_token'));
    }

    #[Test]
    public function hasGrantTypeRejectsUnregisteredGrant(): void
    {
        $client = new OAuthClient(
            id: 'client-7',
            name: 'Test App',
            redirectUris: [],
            grantTypes: ['authorization_code'],
            scopes: [],
            confidential: true,
        );

        self::assertFalse($client->hasGrantType('client_credentials'));
        self::assertFalse($client->hasGrantType('implicit'));
    }

    #[Test]
    public function debugInfoRedactsSecretHash(): void
    {
        $client = new OAuthClient(
            id: 'client-8',
            name: 'Test App',
            redirectUris: ['https://app.example.com/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['openid'],
            confidential: true,
            secretHash: '$2y$10$realhashedvalue',
        );

        $debug = $client->__debugInfo();

        self::assertSame('[REDACTED]', $debug['secretHash']);
        self::assertSame('client-8', $debug['id']);
        self::assertSame('Test App', $debug['name']);
    }

    #[Test]
    public function debugInfoShowsNullSecretHashAsNull(): void
    {
        $client = new OAuthClient(
            id: 'client-9',
            name: 'Public App',
            redirectUris: ['https://app.example.com/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['openid'],
            confidential: false,
            secretHash: null,
        );

        $debug = $client->__debugInfo();

        self::assertNull($debug['secretHash']);
    }
}
