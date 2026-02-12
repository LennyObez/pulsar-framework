<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Features\ExchangeCode;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Config\SocialSsoConfig;
use Pulsar\Extension\SocialSso\Contracts\IdTokenVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\NonceVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthStateManagerInterface;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;
use Pulsar\Extension\SocialSso\Exception\SsoException;
use Pulsar\Extension\SocialSso\Features\ExchangeCode\ExchangeCodeHandler;
use Pulsar\Extension\SocialSso\Features\ExchangeCode\ExchangeCodeRequest;
use Pulsar\Extension\SocialSso\Internal\Provider\OAuthProviderRegistry;

final class ExchangeCodeHandlerTest extends TestCase
{
    #[Test]
    public function handleThrowsOnInvalidState(): void
    {
        $registry = new OAuthProviderRegistry();
        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('verify')->willReturn(false);
        $nonceVerifier = $this->createStub(NonceVerifierInterface::class);
        $idTokenVerifier = $this->createStub(IdTokenVerifierInterface::class);
        $config = SocialSsoConfig::fromArray([]);

        $handler = new ExchangeCodeHandler($registry, $stateManager, $nonceVerifier, $idTokenVerifier, $config);

        $this->expectException(SsoException::class);
        $handler->handle(new ExchangeCodeRequest('mock', 'code', 'invalid-state'));
    }

    #[Test]
    public function handleReturnsTokenSetWithoutIdToken(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'at-ok');

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('mock');
        $provider->method('exchangeCode')->willReturn($tokenSet);

        $registry = new OAuthProviderRegistry();
        $registry->register($provider);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('verify')->willReturn(true);

        $config = SocialSsoConfig::fromArray([
            'require_nonce' => false,
            'providers' => [
                'mock' => ['type' => 'oauth2', 'client_id' => 'c', 'client_secret' => 's'],
            ],
        ]);

        $handler = new ExchangeCodeHandler(
            $registry,
            $stateManager,
            $this->createStub(NonceVerifierInterface::class),
            $this->createStub(IdTokenVerifierInterface::class),
            $config,
        );

        $result = $handler->handle(new ExchangeCodeRequest('mock', 'code', 'state'));

        self::assertSame('at-ok', $result->tokenSet->accessToken);
        self::assertNull($result->verifiedClaims);
    }

    #[Test]
    public function handleThrowsOnUnexpectedIdTokenForOauth2Provider(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'at', idToken: 'some.jwt.token');

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('mock');
        $provider->method('exchangeCode')->willReturn($tokenSet);

        $registry = new OAuthProviderRegistry();
        $registry->register($provider);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('verify')->willReturn(true);

        $config = SocialSsoConfig::fromArray([
            'providers' => [
                'mock' => [
                    'type' => 'oauth2',
                    'client_id' => 'c',
                    'client_secret' => 's',
                    'allow_unverified_id_token' => false,
                ],
            ],
        ]);

        $handler = new ExchangeCodeHandler(
            $registry,
            $stateManager,
            $this->createStub(NonceVerifierInterface::class),
            $this->createStub(IdTokenVerifierInterface::class),
            $config,
        );

        $this->expectException(SsoException::class);
        $handler->handle(new ExchangeCodeRequest('mock', 'code', 'state'));
    }
}
