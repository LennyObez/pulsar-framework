<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Features;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\Auth\Social\Domain\OAuthTokenSet;
use Pulsar\Extension\Auth\Social\Domain\SocialIdentity;
use Pulsar\Extension\Auth\Social\Features\MapIdentity\MapIdentityHandler;
use Pulsar\Extension\Auth\Social\Features\MapIdentity\MapIdentityRequest;

#[CoversClass(MapIdentityHandler::class)]
final class MapIdentityHandlerTest extends TestCase
{
    #[Test]
    public function handleMapsTokenSetToSocialIdentity(): void
    {
        $identity = new SocialIdentity(
            provider: 'google',
            providerUserId: 'google-user-123',
            email: 'user@gmail.com',
            name: 'Google User',
        );

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('mapIdentity')->willReturn($identity);

        $registry = $this->createStub(OAuthProviderRegistryInterface::class);
        $registry->method('get')->willReturn($provider);

        $handler = new MapIdentityHandler($registry);

        $tokenSet = new OAuthTokenSet(accessToken: 'access-token');
        $result = $handler->handle(new MapIdentityRequest(tokenSet: $tokenSet, providerName: 'google'));

        self::assertSame('google-user-123', $result->socialIdentity->providerUserId);
        self::assertSame('user@gmail.com', $result->socialIdentity->email);
    }
}
