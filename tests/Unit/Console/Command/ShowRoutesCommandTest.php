<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\ShowRoutesCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Core\KernelInterface;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;

#[CoversClass(ShowRoutesCommand::class)]
final class ShowRoutesCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $kernel = $this->createStub(KernelInterface::class);
        $command = new ShowRoutesCommand($kernel);

        self::assertSame('show:routes', $command->name);
    }

    #[Test]
    public function noRoutes(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn([]);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('router')->willReturn($router);

        $command = new ShowRoutesCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('show:routes'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No routes registered', $output->buffer);
    }

    #[Test]
    public function displaysRoutes(): void
    {
        $routes = [
            new Route(
                methods: [Method::GET, Method::HEAD],
                path: '/users',
                handler: static fn(): string => 'index',
                name: 'users.index',
                middleware: [],
            ),
            new Route(
                methods: [Method::POST],
                path: '/users',
                handler: [self::class, 'setUp'],
                name: 'users.store',
                middleware: ['App\\Middleware\\AuthMiddleware'],
            ),
        ];

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn($routes);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('router')->willReturn($router);

        $command = new ShowRoutesCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('show:routes'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Routes (2):', $output->buffer);
        self::assertStringContainsString('/users', $output->buffer);
        self::assertStringContainsString('users.index', $output->buffer);
        self::assertStringContainsString('AuthMiddleware', $output->buffer);
    }

    #[Test]
    public function filterByMethod(): void
    {
        $routes = [
            new Route(
                methods: [Method::GET, Method::HEAD],
                path: '/users',
                handler: static fn(): string => 'index',
                name: 'users.index',
                middleware: [],
            ),
            new Route(
                methods: [Method::POST],
                path: '/users',
                handler: static fn(): string => 'store',
                name: 'users.store',
                middleware: [],
            ),
        ];

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn($routes);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('router')->willReturn($router);

        $command = new ShowRoutesCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('show:routes', [], ['method' => 'POST']),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Routes (1):', $output->buffer);
        self::assertStringContainsString('users.store', $output->buffer);
    }

    #[Test]
    public function filterByPath(): void
    {
        $routes = [
            new Route(
                methods: [Method::GET],
                path: '/users',
                handler: static fn(): string => 'index',
                name: 'users.index',
                middleware: [],
            ),
            new Route(
                methods: [Method::GET],
                path: '/orders',
                handler: static fn(): string => 'orders',
                name: 'orders.index',
                middleware: [],
            ),
        ];

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn($routes);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('router')->willReturn($router);

        $command = new ShowRoutesCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('show:routes', [], ['path' => '/orders']),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Routes (1):', $output->buffer);
        self::assertStringContainsString('orders.index', $output->buffer);
    }

    #[Test]
    public function filterMatchesNothingShowsMessage(): void
    {
        $routes = [
            new Route(
                methods: [Method::GET],
                path: '/users',
                handler: static fn(): string => 'index',
                name: 'users.index',
                middleware: [],
            ),
        ];

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn($routes);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('router')->willReturn($router);

        $command = new ShowRoutesCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('show:routes', [], ['method' => 'DELETE']),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No routes match the filter criteria', $output->buffer);
    }
}
