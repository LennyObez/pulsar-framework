<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Internal\Provider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\OAuthRequest;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;
use Pulsar\Extension\SocialSso\Exception\SsoException;
use Pulsar\Extension\SocialSso\Internal\Provider\LocalMockProvider;

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
    public function authorizationUrlIncludesAllParams(): void
    {
        $provider = new LocalMockProvider();
        $request = new OAuthRequest(
            redirectUri: 'https://app.local/cb',
            scopes: ['openid', 'email'],
            state: 'state-val',
            nonce: 'nonce-val',
            codeChallenge: 'challenge-val',
        );

        $url = $provider->authorizationUrl($request);

        self::assertStringContainsString('https://mock.local/authorize?', $url);
        self::assertStringContainsString('redirect_uri=', $url);
        self::assertStringContainsString('state=state-val', $url);
        self::assertStringContainsString('nonce=nonce-val', $url);
        self::assertStringContainsString('code_challenge=challenge-val', $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
    }

    #[Test]
    public function authorizationUrlOmitsNonceAndPkceWhenNull(): void
    {
        $provider = new LocalMockProvider();
        $request = new OAuthRequest(
            redirectUri: 'https://app.local/cb',
            scopes: ['openid'],
            state: 'st',
        );

        $url = $provider->authorizationUrl($request);

        self::assertStringNotContainsString('nonce=', $url);
        self::assertStringNotContainsString('code_challenge=', $url);
    }

    #[Test]
    public function exchangeCodeReturnsPreConfiguredToken(): void
    {
        $provider = new LocalMockProvider();
        $tokenSet = new OAuthTokenSet(accessToken: 'mock-at');
        $provider->setTokenForCode('auth-code-1', $tokenSet);

        $result = $provider->exchangeCode('auth-code-1', 'https://cb');

        self::assertSame('mock-at', $result->accessToken);
    }

    #[Test]
    public function exchangeCodeThrowsForUnknownCode(): void
    {
        $provider = new LocalMockProvider();

        $this->expectException(SsoException::class);
        $provider->exchangeCode('unknown-code', 'https://cb');
    }

    #[Test]
    public function mapIdentityReturnsPreConfiguredIdentity(): void
    {
        $provider = new LocalMockProvider();
        $tokenSet = new OAuthTokenSet(accessToken: 'at-1');
        $identity = new SocialIdentity('mock', 'user-1', 'user@test.com');
        $provider->setTokenForCode('code-1', $tokenSet);
        $provider->setIdentityForCode('code-1', $identity);

        $result = $provider->mapIdentity($tokenSet);

        self::assertSame('user-1', $result->providerUserId);
        self::assertSame('user@test.com', $result->email);
    }

    #[Test]
    public function mapIdentityReturnsFallbackForUnmappedToken(): void
    {
        $provider = new LocalMockProvider();
        $tokenSet = new OAuthTokenSet(accessToken: 'unmapped-at');

        $result = $provider->mapIdentity($tokenSet);

        self::assertSame('mock', $result->provider);
        self::assertStringContainsString('unmapped-at', $result->providerUserId);
        self::assertSame('mock@example.com', $result->email);
    }
}
