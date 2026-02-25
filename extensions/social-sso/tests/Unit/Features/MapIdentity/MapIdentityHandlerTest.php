<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Features\MapIdentity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderInterface;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;
use Pulsar\Extension\SocialSso\Features\MapIdentity\MapIdentityHandler;
use Pulsar\Extension\SocialSso\Features\MapIdentity\MapIdentityRequest;
use Pulsar\Extension\SocialSso\Internal\Provider\OAuthProviderRegistry;

final class MapIdentityHandlerTest extends TestCase
{
    #[Test]
    public function handleDelegatesToProviderAndReturnsResult(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'at');
        $identity = new SocialIdentity('google', 'g-user-1', 'test@example.com');

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('google');
        $provider->method('mapIdentity')->willReturn($identity);

        $registry = new OAuthProviderRegistry();
        $registry->register($provider);

        $handler = new MapIdentityHandler($registry);
        $result = $handler->handle(new MapIdentityRequest($tokenSet, 'google'));

        self::assertSame('g-user-1', $result->socialIdentity->providerUserId);
        self::assertSame('test@example.com', $result->socialIdentity->email);
    }
}
