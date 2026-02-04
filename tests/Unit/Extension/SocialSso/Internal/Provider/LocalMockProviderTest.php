<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Internal\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\OAuthRequest;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;
use Pulsar\Extension\SocialSso\Exception\SsoException;
use Pulsar\Extension\SocialSso\Internal\Provider\LocalMockProvider;

#[CoversClass(LocalMockProvider::class)]
final class LocalMockProviderTest extends TestCase
{
    #[Test]
    public function nameReturnsConfiguredName(): void
    {
        $provider = new LocalMockProvider('test-provider');

        self::assertSame('test-provider', $provider->name());
    }

    #[Test]
    public function nameDefaultsToMock(): void
    {
        $provider = new LocalMockProvider();

        self::assertSame('mock', $provider->name());
    }

    #[Test]
    public function authorizationUrlContainsAllParameters(): void
    {
        $provider = new LocalMockProvider();

        $request = new OAuthRequest(
            redirectUri: 'https://app.local/callback',
            scopes: ['openid', 'email'],
            state: 'test-state',
            nonce: 'test-nonce',
            codeChallenge: 'test-challenge',
        );

        $url = $provider->authorizationUrl($request);

        self::assertStringContainsString('https://mock.local/authorize?', $url);
        self::assertStringContainsString('state=test-state', $url);
        self::assertStringContainsString('nonce=test-nonce', $url);
        self::assertStringContainsString('code_challenge=test-challenge', $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
    }

    #[Test]
    public function exchangeCodeReturnsPreConfiguredTokens(): void
    {
        $provider = new LocalMockProvider();

        $tokenSet = new OAuthTokenSet(accessToken: 'mock-access-token');
        $provider->setTokenForCode('auth-code-123', $tokenSet);

        $result = $provider->exchangeCode('auth-code-123', 'https://app.local/callback');

        self::assertSame('mock-access-token', $result->accessToken);
    }

    #[Test]
    public function exchangeCodeThrowsForUnknownCode(): void
    {
        $provider = new LocalMockProvider();

        $this->expectException(SsoException::class);

        $provider->exchangeCode('unknown-code', 'https://app.local/callback');
    }

    #[Test]
    public function mapIdentityReturnsPreConfiguredIdentity(): void
    {
        $provider = new LocalMockProvider();

        $tokenSet = new OAuthTokenSet(accessToken: 'access-123');
        $identity = new SocialIdentity(provider: 'mock', providerUserId: 'custom-id', email: 'custom@test.com');

        $provider->setTokenForCode('code-1', $tokenSet);
        $provider->setIdentityForCode('code-1', $identity);

        $result = $provider->mapIdentity($tokenSet);

        self::assertSame('custom-id', $result->providerUserId);
        self::assertSame('custom@test.com', $result->email);
    }

    #[Test]
    public function mapIdentityReturnsFallbackForUnmappedToken(): void
    {
        $provider = new LocalMockProvider();

        $tokenSet = new OAuthTokenSet(accessToken: 'some-token');

        $result = $provider->mapIdentity($tokenSet);

        self::assertSame('mock', $result->provider);
        self::assertStringStartsWith('mock-user-', $result->providerUserId);
        self::assertSame('mock@example.com', $result->email);
    }
}
