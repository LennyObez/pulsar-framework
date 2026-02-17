<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Features;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Config\SocialSsoConfig;
use Pulsar\Extension\Auth\Social\Contracts\NonceVerifierInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthStateManagerInterface;
use Pulsar\Extension\Auth\Social\Features\InitiateLogin\InitiateLoginHandler;
use Pulsar\Extension\Auth\Social\Features\InitiateLogin\InitiateLoginRequest;

#[CoversClass(InitiateLoginHandler::class)]
final class InitiateLoginHandlerTest extends TestCase
{
    #[Test]
    public function handleGeneratesStatePkceAndAuthorizationUrl(): void
    {
        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('google');
        $provider->method('authorizationUrl')->willReturn('https://auth.example.com/authorize?state=abc');

        $registry = $this->createStub(OAuthProviderRegistryInterface::class);
        $registry->method('get')->willReturn($provider);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('generate')->willReturn('generated-state-123');

        $nonceVerifier = $this->createStub(NonceVerifierInterface::class);
        $nonceVerifier->method('generate')->willReturn('generated-nonce-456');

        $config = SocialSsoConfig::fromArray([
            'enabled' => true,
            'require_pkce' => true,
            'require_nonce' => true,
            'providers' => [
                'google' => [
                    'type' => 'oidc',
                    'client_id' => 'test-id',
                    'client_secret' => 'test-secret',
                    'authorization_url' => 'https://auth.example.com/authorize',
                    'token_url' => 'https://auth.example.com/token',
                    'jwks_uri' => 'https://auth.example.com/jwks',
                    'issuer' => 'https://auth.example.com',
                    'scopes' => ['openid', 'email'],
                ],
            ],
        ]);

        $handler = new InitiateLoginHandler($registry, $stateManager, $nonceVerifier, $config);

        $result = $handler->handle(new InitiateLoginRequest(providerName: 'google'));

        self::assertSame('https://auth.example.com/authorize?state=abc', $result->authorizationUrl);
        self::assertSame('generated-state-123', $result->state);
        self::assertNotNull($result->pkceChallenge);
        self::assertSame('S256', $result->pkceChallenge->method);
    }

    #[Test]
    public function handleSkipsPkceWhenDisabled(): void
    {
        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('github');
        $provider->method('authorizationUrl')->willReturn('https://github.com/login/oauth/authorize');

        $registry = $this->createStub(OAuthProviderRegistryInterface::class);
        $registry->method('get')->willReturn($provider);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('generate')->willReturn('state-abc');

        $nonceVerifier = $this->createStub(NonceVerifierInterface::class);

        $config = SocialSsoConfig::fromArray([
            'require_pkce' => false,
            'require_nonce' => false,
            'providers' => [
                'github' => [
                    'type' => 'oauth2',
                    'client_id' => 'id',
                    'client_secret' => 'secret',
                    'authorization_url' => 'https://github.com/login/oauth/authorize',
                    'token_url' => 'https://github.com/login/oauth/access_token',
                    'scopes' => ['user:email'],
                ],
            ],
        ]);

        $handler = new InitiateLoginHandler($registry, $stateManager, $nonceVerifier, $config);

        $result = $handler->handle(new InitiateLoginRequest(providerName: 'github'));

        self::assertNull($result->pkceChallenge);
    }

    #[Test]
    public function handleSkipsNonceForOAuth2Providers(): void
    {
        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('github');
        $provider->method('authorizationUrl')->willReturn('https://github.com/login/oauth/authorize');

        $registry = $this->createStub(OAuthProviderRegistryInterface::class);
        $registry->method('get')->willReturn($provider);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('generate')->willReturn('state-abc');

        $nonceVerifier = $this->createMock(NonceVerifierInterface::class);
        $nonceVerifier->expects(self::never())->method('generate');

        $config = SocialSsoConfig::fromArray([
            'require_pkce' => false,
            'require_nonce' => true, // enabled, but provider is oauth2
            'providers' => [
                'github' => [
                    'type' => 'oauth2',
                    'client_id' => 'id',
                    'client_secret' => 'secret',
                    'authorization_url' => 'https://github.com/login/oauth/authorize',
                    'token_url' => 'https://github.com/login/oauth/access_token',
                    'scopes' => ['user:email'],
                ],
            ],
        ]);

        $handler = new InitiateLoginHandler($registry, $stateManager, $nonceVerifier, $config);

        $handler->handle(new InitiateLoginRequest(providerName: 'github'));
    }
}
