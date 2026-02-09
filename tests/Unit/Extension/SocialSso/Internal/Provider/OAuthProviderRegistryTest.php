<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Internal\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderInterface;
use Pulsar\Extension\SocialSso\Exception\SsoException;
use Pulsar\Extension\SocialSso\Internal\Provider\OAuthProviderRegistry;

#[CoversClass(OAuthProviderRegistry::class)]
final class OAuthProviderRegistryTest extends TestCase
{
    #[Test]
    public function registerAndGetProvider(): void
    {
        $registry = new OAuthProviderRegistry();

        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('google');

        $registry->register($provider);

        self::assertSame($provider, $registry->get('google'));
    }

    #[Test]
    public function getThrowsForUnregisteredProvider(): void
    {
        $registry = new OAuthProviderRegistry();

        $this->expectException(SsoException::class);

        $registry->get('nonexistent');
    }
}
