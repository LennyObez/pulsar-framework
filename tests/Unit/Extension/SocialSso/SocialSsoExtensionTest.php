<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Extension\SocialSso\Config\SocialSsoConfig;
use Pulsar\Extension\SocialSso\SocialSsoExtension;
use Pulsar\Extension\SocialSso\SocialSsoServiceProvider;
use Pulsar\Routing\Router;

#[CoversClass(SocialSsoExtension::class)]
final class SocialSsoExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarSocialSso(): void
    {
        $extension = new SocialSsoExtension();

        self::assertSame('pulsar/social-sso', $extension->name());
    }

    #[Test]
    public function registerIsNoOp(): void
    {
        $extension = new SocialSsoExtension();
        $container = new Container();

        $extension->register($container);

        self::assertSame([], $container->getBindings());
    }

    #[Test]
    public function providersReturnsServiceProviderClass(): void
    {
        $extension = new SocialSsoExtension();

        self::assertSame([SocialSsoServiceProvider::class], $extension->providers());
    }

    #[Test]
    public function bootSkipsWhenDisabled(): void
    {
        $extension = new SocialSsoExtension();
        $container = new Container();
        $router = new Router();

        $config = SocialSsoConfig::fromArray(['enabled' => false]);
        $container->instance(SocialSsoConfig::class, $config);

        $extension->boot($container, $router);

        // No routes should have been registered
        self::assertEmpty($router->routes);
    }
}
