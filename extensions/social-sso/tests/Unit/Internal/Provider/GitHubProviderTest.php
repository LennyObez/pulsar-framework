<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Internal\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Config\ProviderConfig;
use Pulsar\Extension\SocialSso\Domain\OAuthRequest;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;
use Pulsar\Extension\SocialSso\Exception\SsoException;
use Pulsar\Extension\SocialSso\Internal\Provider\GitHubHttpClientInterface;
use Pulsar\Extension\SocialSso\Internal\Provider\GitHubProvider;

use function json_encode;

#[CoversClass(GitHubProvider::class)]
final class GitHubProviderTest extends TestCase
{
    private GitHubHttpClientInterface&Stub $httpClient;
    private GitHubProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createStub(GitHubHttpClientInterface::class);

        $config = new ProviderConfig(
            name: 'github',
            type: 'oauth2',
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            authorizationUrl: 'https://github.com/login/oauth/authorize',
            tokenUrl: 'https://github.com/login/oauth/access_token',
            jwksUri: null,
            issuer: null,
            scopes: ['read:user', 'user:email'],
            redirectUri: 'https://app.test/callback',
        );

        $this->provider = new GitHubProvider($config, $this->httpClient);
    }

    public function testName(): void
    {
        self::assertSame('github', $this->provider->name());
    }

    public function testAuthorizationUrl(): void
    {
        $request = new OAuthRequest(
            redirectUri: 'https://app.test/callback',
            scopes: ['read:user', 'user:email'],
            state: 'random-state',
        );

        $url = $this->provider->authorizationUrl($request);

        self::assertStringStartsWith('https://github.com/login/oauth/authorize?', $url);
        self::assertStringContainsString('client_id=test-client-id', $url);
        self::assertStringContainsString('state=random-state', $url);
        self::assertStringContainsString('redirect_uri=', $url);
    }

    public function testExchangeCodeSuccess(): void
    {
        $this->httpClient->method('post')->willReturn(json_encode([
            'access_token' => 'gho_test_token',
            'token_type' => 'bearer',
            'scope' => 'read:user,user:email',
        ]));

        $tokenSet = $this->provider->exchangeCode('auth-code', 'https://app.test/callback');

        self::assertSame('gho_test_token', $tokenSet->accessToken);
        self::assertSame('bearer', $tokenSet->tokenType);
    }

    public function testExchangeCodeFailure(): void
    {
        $this->httpClient->method('post')->willReturn(json_encode([
            'error' => 'bad_verification_code',
            'error_description' => 'The code passed is incorrect or expired.',
        ]));

        $this->expectException(SsoException::class);
        $this->provider->exchangeCode('invalid-code', 'https://app.test/callback');
    }

    public function testMapIdentityWithPublicEmail(): void
    {
        $this->httpClient->method('get')->willReturn(json_encode([
            'id' => 12345,
            'login' => 'janedoe',
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/12345',
        ]));

        $tokenSet = new OAuthTokenSet(accessToken: 'gho_test_token');
        $identity = $this->provider->mapIdentity($tokenSet);

        self::assertSame('github', $identity->provider);
        self::assertSame('12345', $identity->providerUserId);
        self::assertSame('jane@example.com', $identity->email);
        self::assertSame('Jane Doe', $identity->name);
        self::assertSame('https://avatars.githubusercontent.com/u/12345', $identity->avatarUrl);
    }

    public function testMapIdentityFallsBackToLogin(): void
    {
        $this->httpClient->method('get')->willReturn(json_encode([
            'id' => 999,
            'login' => 'coder42',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/999',
        ]));

        $tokenSet = new OAuthTokenSet(accessToken: 'gho_test_token');
        $identity = $this->provider->mapIdentity($tokenSet);

        self::assertSame('coder42', $identity->name);
    }

    public function testMapIdentityFetchesEmailFromEndpoint(): void
    {
        $callCount = 0;
        $this->httpClient->method('get')->willReturnCallback(
            function (string $url) use (&$callCount): string {
                $callCount++;

                if (str_contains($url, '/user/emails')) {
                    return (string) json_encode([
                        ['email' => 'secondary@example.com', 'primary' => false],
                        ['email' => 'primary@example.com', 'primary' => true],
                    ]);
                }

                return (string) json_encode([
                    'id' => 555,
                    'login' => 'testuser',
                    'email' => null,
                ]);
            },
        );

        $tokenSet = new OAuthTokenSet(accessToken: 'gho_test_token');
        $identity = $this->provider->mapIdentity($tokenSet);

        self::assertSame('primary@example.com', $identity->email);
    }
}
