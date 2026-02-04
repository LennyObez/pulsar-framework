<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Gateway;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Config\SocialSsoConfig;
use Pulsar\Extension\SocialSso\Contracts\IdTokenVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\NonceVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthStateManagerInterface;
use Pulsar\Extension\SocialSso\Contracts\SocialIdentityLinkerInterface;
use Pulsar\Extension\SocialSso\Domain\LinkAction;
use Pulsar\Extension\SocialSso\Domain\LinkedIdentityResult;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;
use Pulsar\Extension\SocialSso\Features\ExchangeCode\ExchangeCodeHandler;
use Pulsar\Extension\SocialSso\Features\MapIdentity\MapIdentityHandler;
use Pulsar\Extension\SocialSso\Gateway\SsoGateway;

#[CoversClass(SsoGateway::class)]
final class SsoGatewayTest extends TestCase
{
    #[Test]
    public function fullLoginOrchestratesExchangeMapAndLink(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'token-abc');
        $identity = new SocialIdentity(provider: 'google', providerUserId: '123', email: 'user@gmail.com');
        $linkResult = new LinkedIdentityResult(linked: true, identityId: 'local-42', action: LinkAction::Linked);

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('exchangeCode')->willReturn($tokenSet);
        $provider->method('mapIdentity')->willReturn($identity);

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

        $exchangeHandler = new ExchangeCodeHandler($registry, $stateManager, $nonceVerifier, $idTokenVerifier, $config);
        $mapHandler = new MapIdentityHandler($registry);

        $linker = $this->createStub(SocialIdentityLinkerInterface::class);
        $linker->method('link')->willReturn($linkResult);

        $gateway = new SsoGateway($exchangeHandler, $mapHandler, $linker);

        $result = $gateway->fullLogin('google', 'auth-code', 'valid-state');

        self::assertSame('123', $result->socialIdentity->providerUserId);
        self::assertTrue($result->linkResult->linked);
        self::assertSame('local-42', $result->linkResult->identityId);
        self::assertSame(LinkAction::Linked, $result->linkResult->action);
        self::assertNull($result->verifiedClaims);
    }

    #[Test]
    public function fullLoginReturnsUnlinkedResultWhenLinkerRejectsIdentity(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'token-abc');
        $identity = new SocialIdentity(provider: 'google', providerUserId: '456');
        $linkResult = new LinkedIdentityResult(linked: false, identityId: null, action: LinkAction::Unlinked);

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('exchangeCode')->willReturn($tokenSet);
        $provider->method('mapIdentity')->willReturn($identity);

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

        $exchangeHandler = new ExchangeCodeHandler($registry, $stateManager, $nonceVerifier, $idTokenVerifier, $config);
        $mapHandler = new MapIdentityHandler($registry);

        $linker = $this->createStub(SocialIdentityLinkerInterface::class);
        $linker->method('link')->willReturn($linkResult);

        $gateway = new SsoGateway($exchangeHandler, $mapHandler, $linker);

        $result = $gateway->fullLogin('google', 'code', 'state');

        self::assertFalse($result->linkResult->linked);
        self::assertSame(LinkAction::Unlinked, $result->linkResult->action);
    }
}
