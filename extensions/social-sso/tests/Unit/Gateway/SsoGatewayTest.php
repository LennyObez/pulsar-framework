<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Gateway;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Config\SocialSsoConfig;
use Pulsar\Extension\SocialSso\Contracts\IdTokenVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\NonceVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthStateManagerInterface;
use Pulsar\Extension\SocialSso\Contracts\SocialIdentityLinkerInterface;
use Pulsar\Extension\SocialSso\Domain\LinkAction;
use Pulsar\Extension\SocialSso\Domain\LinkedIdentityResult;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;
use Pulsar\Extension\SocialSso\Features\ExchangeCode\ExchangeCodeHandler;
use Pulsar\Extension\SocialSso\Features\MapIdentity\MapIdentityHandler;
use Pulsar\Extension\SocialSso\Gateway\SsoGateway;
use Pulsar\Extension\SocialSso\Internal\Provider\OAuthProviderRegistry;

final class SsoGatewayTest extends TestCase
{
    #[Test]
    public function fullLoginOrchestratesExchangeMapAndLink(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'at');
        $identity = new SocialIdentity('mock', 'u1', 'u@test.com');
        $linkResult = new LinkedIdentityResult(true, 'id-1', LinkAction::Linked);

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('mock');
        $provider->method('exchangeCode')->willReturn($tokenSet);
        $provider->method('mapIdentity')->willReturn($identity);

        $registry = new OAuthProviderRegistry();
        $registry->register($provider);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('verify')->willReturn(true);

        $nonceVerifier = $this->createStub(NonceVerifierInterface::class);
        $idTokenVerifier = $this->createStub(IdTokenVerifierInterface::class);

        $config = SocialSsoConfig::fromArray([
            'require_pkce' => false,
            'require_nonce' => false,
            'providers' => [
                'mock' => [
                    'type' => 'oauth2',
                    'client_id' => 'cid',
                    'client_secret' => 'cs',
                    'authorization_url' => 'https://auth',
                    'token_url' => 'https://token',
                ],
            ],
        ]);

        $exchangeHandler = new ExchangeCodeHandler(
            $registry,
            $stateManager,
            $nonceVerifier,
            $idTokenVerifier,
            $config,
        );

        $mapHandler = new MapIdentityHandler($registry);

        $linker = $this->createStub(SocialIdentityLinkerInterface::class);
        $linker->method('link')->willReturn($linkResult);

        $gateway = new SsoGateway($exchangeHandler, $mapHandler, $linker);

        $result = $gateway->fullLogin('mock', 'auth-code', 'valid-state');

        self::assertSame($identity, $result->socialIdentity);
        self::assertTrue($result->linkResult->linked);
        self::assertNull($result->verifiedClaims);
    }
}
