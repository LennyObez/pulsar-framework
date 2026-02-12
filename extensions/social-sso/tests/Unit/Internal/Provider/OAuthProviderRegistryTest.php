<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Internal\Provider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderInterface;
use Pulsar\Extension\SocialSso\Exception\SsoException;
use Pulsar\Extension\SocialSso\Internal\Provider\OAuthProviderRegistry;

final class OAuthProviderRegistryTest extends TestCase
{
    #[Test]
    public function registerAndGetProvider(): void
    {
        $registry = new OAuthProviderRegistry();
        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('name')->willReturn('github');

        $registry->register($provider);
        $retrieved = $registry->get('github');

        self::assertSame($provider, $retrieved);
    }

    #[Test]
    public function getThrowsForUnregisteredProvider(): void
    {
        $registry = new OAuthProviderRegistry();

        $this->expectException(SsoException::class);
        $registry->get('nonexistent');
    }

    #[Test]
    public function registerOverwritesPreviousProvider(): void
    {
        $registry = new OAuthProviderRegistry();

        $first = $this->createStub(OAuthProviderInterface::class);
        $first->method('name')->willReturn('google');

        $second = $this->createStub(OAuthProviderInterface::class);
        $second->method('name')->willReturn('google');

        $registry->register($first);
        $registry->register($second);

        self::assertSame($second, $registry->get('google'));
    }
}
