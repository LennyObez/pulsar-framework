<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Auth\WebAuthn\WebAuthnExtension;
use Pulsar\Extension\Auth\WebAuthn\WebAuthnServiceProvider;
use Pulsar\Routing\RouterInterface;

use function is_callable;

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
    public function providersReturnsWebAuthnServiceProvider(): void
    {
        $extension = new WebAuthnExtension();
        $providers = $extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(WebAuthnServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function bootRegistersWebAuthnRouteGroup(): void
    {
        $extension = new WebAuthnExtension();

        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);

        $router->expects(self::once())
            ->method('group')
            ->with('/webauthn', self::callback(static fn(mixed $arg): bool => is_callable($arg)));

        $extension->boot($container, $router);
    }
}
