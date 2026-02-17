<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Internal\Provider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Config\ProviderConfig;
use Pulsar\Extension\Auth\Social\Domain\OAuthRequest;
use Pulsar\Extension\Auth\Social\Domain\OAuthTokenSet;
use Pulsar\Extension\Auth\Social\Exception\SsoException;
use Pulsar\Extension\Auth\Social\Internal\Provider\GitHubHttpClientInterface;
use Pulsar\Extension\Auth\Social\Internal\Provider\GitHubProvider;

final class GitHubProviderTest extends TestCase
{
    #[Test]
    public function nameReturnsGithub(): void
    {
        $provider = $this->buildProvider();

        self::assertSame('github', $provider->name());
    }

    #[Test]
    public function authorizationUrlContainsRequiredParams(): void
    {
        $provider = $this->buildProvider();
        $request = new OAuthRequest(
            redirectUri: 'https://app.test/callback',
            scopes: ['read:user', 'user:email'],
            state: 'state-abc',
        );

        $url = $provider->authorizationUrl($request);

        self::assertStringContainsString('client_id=gh-client', $url);
        self::assertStringContainsString('redirect_uri=', $url);
        self::assertStringContainsString('state=state-abc', $url);
        self::assertStringContainsString('github.com/login/oauth/authorize', $url);
    }

    #[Test]
    public function authorizationUrlUsesCustomUrlWhenConfigured(): void
    {
        $config = ProviderConfig::fromArray('github', [
            'client_id' => 'gh-client',
            'client_secret' => 'gh-secret',
            'authorization_url' => 'https://custom-auth.test/authorize',
        ]);

        $provider = new GitHubProvider($config, $this->createStub(GitHubHttpClientInterface::class));

        $url = $provider->authorizationUrl(new OAuthRequest(
            redirectUri: 'https://app.test/cb',
            scopes: [],
            state: 's',
        ));

        self::assertStringStartsWith('https://custom-auth.test/authorize', $url);
    }

    #[Test]
    public function exchangeCodeReturnsTokenSet(): void
    {
        $httpClient = $this->createStub(GitHubHttpClientInterface::class);
        $httpClient->method('post')->willReturn(json_encode([
            'access_token' => 'gho_abc123',
            'token_type' => 'bearer',
            'scope' => 'read:user,user:email',
        ]));

        $provider = $this->buildProvider($httpClient);
        $tokens = $provider->exchangeCode('auth-code-xyz', 'https://app.test/cb');

        self::assertSame('gho_abc123', $tokens->accessToken);
        self::assertSame('bearer', $tokens->tokenType);
    }

    #[Test]
    public function exchangeCodeThrowsOnMissingToken(): void
    {
        $httpClient = $this->createStub(GitHubHttpClientInterface::class);
        $httpClient->method('post')->willReturn(json_encode(['error' => 'bad_verification_code']));

        $provider = $this->buildProvider($httpClient);

        $this->expectException(SsoException::class);
        $provider->exchangeCode('invalid-code', 'https://app.test/cb');
    }

    #[Test]
    public function mapIdentityExtractsUserProfile(): void
    {
        $httpClient = $this->createStub(GitHubHttpClientInterface::class);
        $httpClient->method('get')
            ->willReturnCallback(function (string $url): string {
                if (str_contains($url, '/user/emails')) {
                    return json_encode([
                        ['email' => 'primary@test.com', 'primary' => true, 'verified' => true],
                    ]);
                }

                return json_encode([
                    'id' => 12345,
                    'login' => 'octocat',
                    'name' => 'The Octocat',
                    'avatar_url' => 'https://github.com/octocat.png',
                    'email' => null,
                ]);
            });

        $provider = $this->buildProvider($httpClient);
        $tokenSet = new OAuthTokenSet(accessToken: 'gho_test', tokenType: 'bearer');

        $identity = $provider->mapIdentity($tokenSet);

        self::assertSame('github', $identity->provider);
        self::assertSame('12345', $identity->providerUserId);
        self::assertSame('primary@test.com', $identity->email);
        self::assertSame('The Octocat', $identity->name);
        self::assertSame('https://github.com/octocat.png', $identity->avatarUrl);
    }

    #[Test]
    public function mapIdentityUsesPublicEmailIfAvailable(): void
    {
        $httpClient = $this->createStub(GitHubHttpClientInterface::class);
        $httpClient->method('get')->willReturn(json_encode([
            'id' => 99,
            'login' => 'dev',
            'name' => null,
            'email' => 'public@example.com',
            'avatar_url' => null,
        ]));

        $provider = $this->buildProvider($httpClient);
        $tokenSet = new OAuthTokenSet(accessToken: 'token', tokenType: 'bearer');

        $identity = $provider->mapIdentity($tokenSet);

        self::assertSame('public@example.com', $identity->email);
        self::assertSame('dev', $identity->name); // falls back to login
    }

    private function buildProvider(?GitHubHttpClientInterface $httpClient = null): GitHubProvider
    {
        $config = ProviderConfig::fromArray('github', [
            'client_id' => 'gh-client',
            'client_secret' => 'gh-secret',
        ]);

        return new GitHubProvider(
            $config,
            $httpClient ?? $this->createStub(GitHubHttpClientInterface::class),
        );
    }
}
