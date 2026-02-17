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
use Pulsar\Extension\SocialSso\Features\ExchangeCode\ExchangeCodeHandler;
use Pulsar\Extension\SocialSso\Features\ExchangeCode\ExchangeCodeRequest;
use Pulsar\Extension\SocialSso\Internal\Provider\OAuthProviderRegistry;

final class ExchangeCodePkceTest extends TestCase
{
    #[Test]
    public function handleCallsRetrievePkceVerifierThroughInterface(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'at-ok');

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('mock');
        $provider->method('exchangeCode')->willReturn($tokenSet);

        $registry = new OAuthProviderRegistry();
        $registry->register($provider);

        $stateManager = $this->createMock(OAuthStateManagerInterface::class);
        $stateManager->method('verify')->willReturn(true);
        $stateManager->expects(self::once())
            ->method('retrievePkceVerifier')
            ->with('state-token')
            ->willReturn('pkce-verifier-value');

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

        $result = $handler->handle(new ExchangeCodeRequest('mock', 'code', 'state-token'));

        self::assertSame('at-ok', $result->tokenSet->accessToken);
    }

    #[Test]
    public function handlePassesNullVerifierWhenNoPkceStored(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'at-no-pkce');

        $provider = $this->createMock(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('mock');
        $provider->expects(self::once())
            ->method('exchangeCode')
            ->with('code', '', null)
            ->willReturn($tokenSet);

        $registry = new OAuthProviderRegistry();
        $registry->register($provider);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('verify')->willReturn(true);
        $stateManager->method('retrievePkceVerifier')->willReturn(null);

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

        $result = $handler->handle(new ExchangeCodeRequest('mock', 'code', 'state-token'));
        self::assertSame('at-no-pkce', $result->tokenSet->accessToken);
    }

    #[Test]
    public function handlePassesPkceVerifierToProviderExchangeCode(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'at-with-pkce');

        $provider = $this->createMock(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('mock');
        $provider->expects(self::once())
            ->method('exchangeCode')
            ->with('code', '', 'my-verifier')
            ->willReturn($tokenSet);

        $registry = new OAuthProviderRegistry();
        $registry->register($provider);

        $stateManager = $this->createStub(OAuthStateManagerInterface::class);
        $stateManager->method('verify')->willReturn(true);
        $stateManager->method('retrievePkceVerifier')->willReturn('my-verifier');

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

        $result = $handler->handle(new ExchangeCodeRequest('mock', 'code', 'state-token'));
        self::assertSame('at-with-pkce', $result->tokenSet->accessToken);
    }
}
