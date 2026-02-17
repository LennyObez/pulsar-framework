<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Gateway;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Config\SocialSsoConfig;
use Pulsar\Extension\Auth\Social\Contracts\IdTokenVerifierInterface;
use Pulsar\Extension\Auth\Social\Contracts\NonceVerifierInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthStateManagerInterface;
use Pulsar\Extension\Auth\Social\Contracts\SocialIdentityLinkerInterface;
use Pulsar\Extension\Auth\Social\Domain\LinkAction;
use Pulsar\Extension\Auth\Social\Domain\LinkedIdentityResult;
use Pulsar\Extension\Auth\Social\Domain\OAuthTokenSet;
use Pulsar\Extension\Auth\Social\Domain\SocialIdentity;
use Pulsar\Extension\Auth\Social\Features\ExchangeCode\ExchangeCodeHandler;
use Pulsar\Extension\Auth\Social\Features\MapIdentity\MapIdentityHandler;
use Pulsar\Extension\Auth\Social\Gateway\SsoGateway;

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
