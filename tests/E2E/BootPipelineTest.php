<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Kernel;
use Pulsar\Core\Version;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Routing\Router;

/**
 * End-to-end tests for the kernel boot pipeline.
 *
 * Verifies that the kernel boots correctly, registers core services,
 * and maintains idempotency across multiple boot cycles.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(Version::class)]
#[CoversClass(Container::class)]
final class BootPipelineTest extends TestCase
{
    private function createRequest(string $path = '/'): Request
    {
        return new Request(
            method: Method::GET,
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    #[Test]
    public function kernelBootsSuccessfully(): void
    {
        $kernel = new Kernel();

        self::assertFalse($kernel->booted);

        $kernel->boot();

        self::assertTrue($kernel->booted);
    }

    #[Test]
    public function coreServicesAreRegisteredAfterBoot(): void
    {
        $kernel = new Kernel();
        $kernel->boot();

        $container = $kernel->container();

        self::assertTrue($container->has(Kernel::class), 'Kernel should be registered in the container');
        self::assertTrue($container->has(Router::class), 'Router should be registered in the container');
        self::assertTrue($container->has(ContainerInterface::class), 'ContainerInterface should be registered in the container');
        self::assertTrue($container->has(MiddlewareRegistry::class), 'MiddlewareRegistry should be registered in the container');
    }

    #[Test]
    public function containerReturnsCorrectSingletonInstances(): void
    {
        $kernel = new Kernel();
        $kernel->boot();

        $container = $kernel->container();

        self::assertSame($kernel, $container->get(Kernel::class));
        self::assertSame($kernel->router(), $container->get(Router::class));
    }

    #[Test]
    public function bootPipelineIsIdempotent(): void
    {
        $kernel = new Kernel();

        $kernel->boot();
        self::assertTrue($kernel->booted);

        // Second boot should not throw or change state
        $kernel->boot();
        self::assertTrue($kernel->booted);

        // Services should still be accessible
        $container = $kernel->container();
        self::assertSame($kernel, $container->get(Kernel::class));
    }

    #[Test]
    public function kernelVersionReportsCorrectly(): void
    {
        self::assertSame('1.0.0-rc.10', Version::full());
        self::assertSame('1.0.0', Version::short());
        self::assertSame(1, Version::MAJOR);
        self::assertSame(0, Version::MINOR);
        self::assertSame(0, Version::PATCH);
        self::assertSame('-rc.10', Version::PRERELEASE_SUFFIX);
    }

    #[Test]
    public function bootIsTriggeredAutomaticallyOnFirstHandle(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('auto-booted'));

        self::assertFalse($kernel->booted);

        $response = $kernel->handle($this->createRequest());

        self::assertTrue($kernel->booted);
        self::assertSame('auto-booted', $response->body);
    }

    #[Test]
    public function shutdownResetsBootedState(): void
    {
        $kernel = new Kernel();
        $kernel->boot();
        self::assertTrue($kernel->booted);

        $kernel->shutdown();
        self::assertFalse($kernel->booted);
    }

    #[Test]
    public function kernelCanRebootAfterShutdown(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('rebooted'));

        $kernel->boot();
        self::assertTrue($kernel->booted);

        $kernel->shutdown();
        self::assertFalse($kernel->booted);

        // Handling a request triggers re-boot
        $response = $kernel->handle($this->createRequest());

        self::assertTrue($kernel->booted);
        self::assertSame('rebooted', $response->body);
    }

    #[Test]
    public function customContainerIsUsedWhenProvided(): void
    {
        $container = new Container();
        $kernel = new Kernel($container);
        $kernel->boot();

        self::assertSame($container, $kernel->container());
        self::assertTrue($container->has(Kernel::class));
        self::assertTrue($container->has(Router::class));
    }

    #[Test]
    public function customRouterIsUsedWhenProvided(): void
    {
        $router = new Router();
        $router->get('/custom', fn() => Response::text('custom-router'));

        $kernel = new Kernel(router: $router);
        $kernel->boot();

        self::assertSame($router, $kernel->router());

        $response = $kernel->handle($this->createRequest('/custom'));

        self::assertSame('custom-router', $response->body);
    }

    #[Test]
    public function routerIsAccessibleBeforeBoot(): void
    {
        $kernel = new Kernel();

        $router = $kernel->router();

        self::assertInstanceOf(Router::class, $router);
        self::assertSame(0, $router->count());
    }

    #[Test]
    public function containerIsAccessibleBeforeBoot(): void
    {
        $kernel = new Kernel();

        $container = $kernel->container();

        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertTrue($container->has(Kernel::class));
    }
}
