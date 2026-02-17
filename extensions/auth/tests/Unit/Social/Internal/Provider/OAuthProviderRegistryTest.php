<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Internal\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderInterface;
use Pulsar\Extension\Auth\Social\Exception\SsoException;
use Pulsar\Extension\Auth\Social\Internal\Provider\OAuthProviderRegistry;

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
