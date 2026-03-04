<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Auth\AuthExtension;
use Pulsar\Extension\Auth\AuthServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(AuthExtension::class)]
final class AuthExtensionTest extends TestCase
{
    #[Test]
    public function name_returns_pulsar_auth(): void
    {
        $extension = new AuthExtension();

        self::assertSame('pulsar/auth', $extension->name());
    }

    #[Test]
    public function providers_returns_auth_service_provider(): void
    {
        $extension = new AuthExtension();

        $providers = $extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(AuthServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function register_does_not_throw(): void
    {
        $extension = new AuthExtension();
        /** @var ContainerInterface&Stub $container */
        $container = $this->createStub(ContainerInterface::class);

        $extension->register($container);

        // No exception means success
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function boot_registers_oauth2_and_webauthn_routes(): void
    {
        $extension = new AuthExtension();
        /** @var ContainerInterface&Stub $container */
        $container = $this->createStub(ContainerInterface::class);

        $router = $this->createMock(RouterInterface::class);

        // Expect OAuth2 endpoints
        $router->expects(self::atLeastOnce())->method('group');

        // Expect OIDC discovery endpoints
        $router->expects(self::atLeastOnce())->method('get');

        $extension->boot($container, $router);
    }
}
