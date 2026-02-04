<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Features;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Config\SocialSsoConfig;
use Pulsar\Extension\SocialSso\Contracts\IdTokenVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\NonceVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthStateManagerInterface;
use Pulsar\Extension\SocialSso\Domain\IdTokenClaims;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;
use Pulsar\Extension\SocialSso\Exception\SsoException;
use Pulsar\Extension\SocialSso\Features\ExchangeCode\ExchangeCodeHandler;
use Pulsar\Extension\SocialSso\Features\ExchangeCode\ExchangeCodeRequest;

#[CoversClass(ExchangeCodeHandler::class)]
final class ExchangeCodeHandlerTest extends TestCase
{
    #[Test]
    public function handleExchangesCodeForTokens(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'access-token-123');

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('google');
        $provider->method('exchangeCode')->willReturn($tokenSet);

        $registry = $this->createStub(OAuthProviderRegistryInterface::class);
        $registry->method('get')->willReturn($provider);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('verify')->willReturn(true);

        $nonceVerifier = $this->createStub(NonceVerifierInterface::class);
        $idTokenVerifier = $this->createStub(IdTokenVerifierInterface::class);

        $config = SocialSsoConfig::fromArray([
            'providers' => [
                'google' => [
                    'type' => 'oauth2',
                    'client_id' => 'id',
                    'client_secret' => 'secret',
                    'authorization_url' => 'https://auth.example.com/authorize',
                    'token_url' => 'https://auth.example.com/token',
                    'scopes' => [],
                ],
            ],
        ]);

        $handler = new ExchangeCodeHandler($registry, $stateManager, $nonceVerifier, $idTokenVerifier, $config);

        $result = $handler->handle(new ExchangeCodeRequest(
            providerName: 'google',
            code: 'auth-code',
            state: 'valid-state',
        ));

        self::assertSame('access-token-123', $result->tokenSet->accessToken);
        self::assertNull($result->verifiedClaims);
    }

    #[Test]
    public function handleThrowsForInvalidState(): void
    {
        $registry = $this->createStub(OAuthProviderRegistryInterface::class);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('verify')->willReturn(false);

        $nonceVerifier = $this->createStub(NonceVerifierInterface::class);
        $idTokenVerifier = $this->createStub(IdTokenVerifierInterface::class);

        $config = SocialSsoConfig::fromArray([
            'providers' => [
                'google' => [
                    'type' => 'oidc',
                    'client_id' => 'id',
                    'client_secret' => 'secret',
                    'authorization_url' => 'https://auth.example.com/authorize',
                    'token_url' => 'https://auth.example.com/token',
                    'scopes' => [],
                ],
            ],
        ]);

        $handler = new ExchangeCodeHandler($registry, $stateManager, $nonceVerifier, $idTokenVerifier, $config);

        $this->expectException(SsoException::class);

        $handler->handle(new ExchangeCodeRequest(
            providerName: 'google',
            code: 'auth-code',
            state: 'invalid-state',
        ));
    }

    #[Test]
    public function handleVerifiesIdTokenWhenPresent(): void
    {
        $tokenSet = new OAuthTokenSet(
            accessToken: 'access-token',
            idToken: 'fake.id.token',
        );

        $verifiedClaims = new IdTokenClaims(
            sub: 'user-1',
            iss: 'https://auth.example.com',
            aud: 'client-id',
            exp: 9999999999,
            iat: 1000000000,
            nonce: 'test-nonce',
        );

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('exchangeCode')->willReturn($tokenSet);

        $registry = $this->createStub(OAuthProviderRegistryInterface::class);
        $registry->method('get')->willReturn($provider);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('verify')->willReturn(true);

        $nonceVerifier = $this->createStub(NonceVerifierInterface::class);
        $nonceVerifier->method('verify')->willReturn(true);

        $idTokenVerifier = $this->createStub(IdTokenVerifierInterface::class);
        $idTokenVerifier->method('verify')->willReturn($verifiedClaims);

        $config = SocialSsoConfig::fromArray([
            'require_nonce' => true,
            'providers' => [
                'google' => [
                    'type' => 'oidc',
                    'client_id' => 'client-id',
                    'client_secret' => 'secret',
                    'authorization_url' => 'https://auth.example.com/authorize',
                    'token_url' => 'https://auth.example.com/token',
                    'jwks_uri' => 'https://auth.example.com/jwks',
                    'issuer' => 'https://auth.example.com',
                    'scopes' => ['openid'],
                ],
            ],
        ]);

        $handler = new ExchangeCodeHandler($registry, $stateManager, $nonceVerifier, $idTokenVerifier, $config);

        $result = $handler->handle(new ExchangeCodeRequest(
            providerName: 'google',
            code: 'code',
            state: 'state',
        ));

        self::assertNotNull($result->verifiedClaims);
        self::assertSame('user-1', $result->verifiedClaims->sub);
    }

    #[Test]
    public function handleRejectsUnexpectedIdTokenOnOauth2Provider(): void
    {
        $tokenSet = new OAuthTokenSet(
            accessToken: 'access-token',
            idToken: 'unexpected.id.token',
        );

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('exchangeCode')->willReturn($tokenSet);

        $registry = $this->createStub(OAuthProviderRegistryInterface::class);
        $registry->method('get')->willReturn($provider);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('verify')->willReturn(true);

        $nonceVerifier = $this->createStub(NonceVerifierInterface::class);
        $idTokenVerifier = $this->createStub(IdTokenVerifierInterface::class);

        $config = SocialSsoConfig::fromArray([
            'providers' => [
                'github' => [
                    'type' => 'oauth2',
                    'client_id' => 'id',
                    'client_secret' => 'secret',
                    'authorization_url' => 'https://github.com/authorize',
                    'token_url' => 'https://github.com/token',
                    'scopes' => [],
                    'allow_unverified_id_token' => false,
                ],
            ],
        ]);

        $handler = new ExchangeCodeHandler($registry, $stateManager, $nonceVerifier, $idTokenVerifier, $config);

        $this->expectException(SsoException::class);

        $handler->handle(new ExchangeCodeRequest(
            providerName: 'github',
            code: 'code',
            state: 'state',
        ));
    }

    #[Test]
    public function handleThrowsForInvalidNonce(): void
    {
        $tokenSet = new OAuthTokenSet(
            accessToken: 'access-token',
            idToken: 'fake.id.token',
        );

        $verifiedClaims = new IdTokenClaims(
            sub: 'user-1',
            iss: 'https://auth.example.com',
            aud: 'client-id',
            exp: 9999999999,
            iat: 1000000000,
            nonce: 'bad-nonce',
        );

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('exchangeCode')->willReturn($tokenSet);

        $registry = $this->createStub(OAuthProviderRegistryInterface::class);
        $registry->method('get')->willReturn($provider);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('verify')->willReturn(true);

        $nonceVerifier = $this->createStub(NonceVerifierInterface::class);
        $nonceVerifier->method('verify')->willReturn(false);

        $idTokenVerifier = $this->createStub(IdTokenVerifierInterface::class);
        $idTokenVerifier->method('verify')->willReturn($verifiedClaims);

        $config = SocialSsoConfig::fromArray([
            'require_nonce' => true,
            'providers' => [
                'google' => [
                    'type' => 'oidc',
                    'client_id' => 'client-id',
                    'client_secret' => 'secret',
                    'authorization_url' => 'https://auth.example.com/authorize',
                    'token_url' => 'https://auth.example.com/token',
                    'jwks_uri' => 'https://auth.example.com/jwks',
                    'issuer' => 'https://auth.example.com',
                    'scopes' => ['openid'],
                ],
            ],
        ]);

        $handler = new ExchangeCodeHandler($registry, $stateManager, $nonceVerifier, $idTokenVerifier, $config);

        $this->expectException(SsoException::class);

        $handler->handle(new ExchangeCodeRequest(
            providerName: 'google',
            code: 'code',
            state: 'state',
        ));
    }
}
