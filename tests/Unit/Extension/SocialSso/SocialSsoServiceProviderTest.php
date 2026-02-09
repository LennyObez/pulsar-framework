<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Extension\SocialSso\Config\SocialSsoConfig;
use Pulsar\Extension\SocialSso\Contracts\IdTokenVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\JwtSignatureDriverInterface;
use Pulsar\Extension\SocialSso\Contracts\NonceVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthStateManagerInterface;
use Pulsar\Extension\SocialSso\Contracts\SocialIdentityLinkerInterface;
use Pulsar\Extension\SocialSso\Contracts\SsoGatewayInterface;
use Pulsar\Extension\SocialSso\Gateway\SsoGateway;
use Pulsar\Extension\SocialSso\Internal\Token\JwksFetcher;
use Pulsar\Extension\SocialSso\SocialSsoServiceProvider;

#[CoversClass(SocialSsoServiceProvider::class)]
final class SocialSsoServiceProviderTest extends TestCase
{
    #[Test]
    public function registerBindsAllServices(): void
    {
        $provider = new SocialSsoServiceProvider();
        $container = new Container();

        $provider->register($container);

        self::assertTrue($container->has(SocialSsoConfig::class));
        self::assertTrue($container->has(OAuthProviderRegistryInterface::class));
        self::assertTrue($container->has(JwtSignatureDriverInterface::class));
        self::assertTrue($container->has(JwksFetcher::class));
        self::assertTrue($container->has(IdTokenVerifierInterface::class));
        self::assertTrue($container->has(SocialIdentityLinkerInterface::class));
        self::assertTrue($container->has(SsoGateway::class));
        self::assertTrue($container->has(SsoGatewayInterface::class));
    }

    #[Test]
    public function providesReturnsAllBoundClasses(): void
    {
        $provider = new SocialSsoServiceProvider();

        $provides = $provider->provides();

        self::assertContains(SocialSsoConfig::class, $provides);
        self::assertContains(OAuthProviderRegistryInterface::class, $provides);
        self::assertContains(OAuthStateManagerInterface::class, $provides);
        self::assertContains(NonceVerifierInterface::class, $provides);
        self::assertContains(JwtSignatureDriverInterface::class, $provides);
        self::assertContains(JwksFetcher::class, $provides);
        self::assertContains(IdTokenVerifierInterface::class, $provides);
        self::assertContains(SocialIdentityLinkerInterface::class, $provides);
        self::assertContains(SsoGateway::class, $provides);
        self::assertContains(SsoGatewayInterface::class, $provides);
    }
}
