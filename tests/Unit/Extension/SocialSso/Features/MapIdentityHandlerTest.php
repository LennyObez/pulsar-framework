<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Features;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;
use Pulsar\Extension\SocialSso\Features\MapIdentity\MapIdentityHandler;
use Pulsar\Extension\SocialSso\Features\MapIdentity\MapIdentityRequest;

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
        $registry->method('get')->with('google')->willReturn($provider);

        $handler = new MapIdentityHandler($registry);

        $tokenSet = new OAuthTokenSet(accessToken: 'access-token');
        $result = $handler->handle(new MapIdentityRequest(tokenSet: $tokenSet, providerName: 'google'));

        self::assertSame('google-user-123', $result->socialIdentity->providerUserId);
        self::assertSame('user@gmail.com', $result->socialIdentity->email);
    }
}
