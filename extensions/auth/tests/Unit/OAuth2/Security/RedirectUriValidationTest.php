<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Client\OAuthClient;

/**
 * Security conformance tests for redirect URI validation.
 *
 * Validates that the OAuthClient enforces strict exact-match redirect URI
 * comparison with no wildcard, path traversal, or scheme mismatch support.
 */
#[CoversClass(OAuthClient::class)]
final class RedirectUriValidationTest extends TestCase
{
    private OAuthClient $client;

    protected function setUp(): void
    {
        $this->client = new OAuthClient(
            id: 'redirect-test-client',
            name: 'Test App',
            redirectUris: [
                'https://app.example.com/callback',
                'https://app.example.com:8443/oauth/return',
            ],
            grantTypes: ['authorization_code'],
            scopes: ['openid'],
            confidential: true,
            secretHash: '$2y$10$hash',
        );
    }

    #[Test]
    public function exactMatchRequired(): void
    {
        self::assertTrue($this->client->hasRedirectUri('https://app.example.com/callback'));
    }

    #[Test]
    public function noWildcardSupport(): void
    {
        self::assertFalse($this->client->hasRedirectUri('https://app.example.com/*'));
        self::assertFalse($this->client->hasRedirectUri('https://*.example.com/callback'));
        self::assertFalse($this->client->hasRedirectUri('*'));
    }

    #[Test]
    public function pathTraversalRejected(): void
    {
        self::assertFalse($this->client->hasRedirectUri('https://app.example.com/callback/../evil'));
        self::assertFalse($this->client->hasRedirectUri('https://app.example.com/callback/..'));
    }

    #[Test]
    public function differentSchemeRejected(): void
    {
        self::assertFalse($this->client->hasRedirectUri('http://app.example.com/callback'));
    }

    #[Test]
    public function differentHostRejected(): void
    {
        self::assertFalse($this->client->hasRedirectUri('https://evil.example.com/callback'));
        self::assertFalse($this->client->hasRedirectUri('https://app.example.com.evil.com/callback'));
    }

    #[Test]
    public function differentPortRejected(): void
    {
        self::assertFalse($this->client->hasRedirectUri('https://app.example.com:9999/callback'));
    }

    #[Test]
    public function queryStringAdditionRejected(): void
    {
        self::assertFalse($this->client->hasRedirectUri('https://app.example.com/callback?redirect=evil'));
    }

    #[Test]
    public function fragmentAdditionRejected(): void
    {
        self::assertFalse($this->client->hasRedirectUri('https://app.example.com/callback#fragment'));
    }

    #[Test]
    public function emptyUriRejected(): void
    {
        self::assertFalse($this->client->hasRedirectUri(''));
    }

    #[Test]
    public function portedUriExactMatch(): void
    {
        self::assertTrue($this->client->hasRedirectUri('https://app.example.com:8443/oauth/return'));
    }
}
