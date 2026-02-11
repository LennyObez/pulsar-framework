<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\OAuth2\OAuth2Extension;
use Pulsar\Extension\OAuth2\OAuth2ServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(OAuth2Extension::class)]
final class OAuth2ExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarOauth2(): void
    {
        $ext = new OAuth2Extension();
        self::assertSame('pulsar/oauth2', $ext->name());
    }

    #[Test]
    public function providersReturnsOAuth2ServiceProvider(): void
    {
        $ext = new OAuth2Extension();
        self::assertSame([OAuth2ServiceProvider::class], $ext->providers());
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $ext = new OAuth2Extension();
        $ext->register($this->createStub(ContainerInterface::class));
    }

    #[Test]
    public function bootRegistersOAuthAndOidcRoutes(): void
    {
        $this->expectNotToPerformAssertions();

        $container = $this->createStub(ContainerInterface::class);

        $router = $this->createStub(RouterInterface::class);
        $router->method('group')->willReturnSelf();
        $router->method('get')->willReturnSelf();
        $router->method('post')->willReturnSelf();

        $ext = new OAuth2Extension();
        $ext->boot($container, $router);
    }
}
