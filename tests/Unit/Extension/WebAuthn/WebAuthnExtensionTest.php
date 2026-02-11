<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\WebAuthn\WebAuthnExtension;
use Pulsar\Extension\WebAuthn\WebAuthnServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(WebAuthnExtension::class)]
final class WebAuthnExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarWebauthn(): void
    {
        $extension = new WebAuthnExtension();

        self::assertSame('pulsar/webauthn', $extension->name());
    }

    #[Test]
    public function providersReturnsServiceProviderClass(): void
    {
        $extension = new WebAuthnExtension();

        self::assertSame([WebAuthnServiceProvider::class], $extension->providers());
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $container = $this->createStub(ContainerInterface::class);
        $extension = new WebAuthnExtension();

        $extension->register($container);
    }

    #[Test]
    public function bootRegistersWebauthnRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        /** @var RouterInterface&Stub $router */
        $router = $this->createStub(RouterInterface::class);

        $groupCalled = false;
        $router->method('group')->willReturnCallback(
            function (string $prefix, callable $callback) use (&$groupCalled, $router): RouterInterface {
                self::assertSame('/webauthn', $prefix);
                $groupCalled = true;
                $callback($router);

                return $router;
            },
        );

        $extension = new WebAuthnExtension();
        $extension->boot($container, $router);

        self::assertTrue($groupCalled);
    }
}
