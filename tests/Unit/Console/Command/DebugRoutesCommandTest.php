<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\DebugRoutesCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;

#[CoversClass(DebugRoutesCommand::class)]
final class DebugRoutesCommandTest extends TestCase
{
    #[Test]
    public function it_has_correct_name(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $command = new DebugRoutesCommand($router);

        self::assertSame('debug:routes', $command->name);
    }

    #[Test]
    public function it_shows_empty_message_when_no_routes(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn([]);

        $command = new DebugRoutesCommand($router);
        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('No routes registered', $output->buffer);
    }

    #[Test]
    public function it_displays_routes_with_method_and_path(): void
    {
        $routes = [
            new Route(path: '/users', handler: 'UserController::index', methods: [Method::GET], name: 'users.index'),
            new Route(path: '/users', handler: 'UserController::store', methods: [Method::POST], name: 'users.store'),
        ];

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn($routes);

        $command = new DebugRoutesCommand($router);
        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('GET', $output->buffer);
        self::assertStringContainsString('POST', $output->buffer);
        self::assertStringContainsString('/users', $output->buffer);
        self::assertStringContainsString('users.index', $output->buffer);
    }

    #[Test]
    public function it_filters_by_method(): void
    {
        $routes = [
            new Route(path: '/users', handler: 'Controller::index', methods: [Method::GET], name: 'get-route'),
            new Route(path: '/users', handler: 'Controller::store', methods: [Method::POST], name: 'post-route'),
        ];

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn($routes);

        $command = new DebugRoutesCommand($router);
        $input = new ArrayInput(arguments: [], options: ['method' => 'GET']);
        $output = new BufferedOutput();

        $command->execute($input, $output);

        self::assertStringContainsString('get-route', $output->buffer);
        self::assertStringNotContainsString('post-route', $output->buffer);
    }

    #[Test]
    public function it_filters_by_path(): void
    {
        $routes = [
            new Route(path: '/users', handler: 'fn', methods: [Method::GET], name: 'users'),
            new Route(path: '/posts', handler: 'fn', methods: [Method::GET], name: 'posts'),
        ];

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn($routes);

        $command = new DebugRoutesCommand($router);
        $input = new ArrayInput(arguments: [], options: ['path' => '/posts']);
        $output = new BufferedOutput();

        $command->execute($input, $output);

        self::assertStringContainsString('posts', $output->buffer);
        self::assertStringNotContainsString('users', $output->buffer);
    }

    #[Test]
    public function it_filters_by_name(): void
    {
        $routes = [
            new Route(path: '/api/users', handler: 'fn', methods: [Method::GET], name: 'api.users.index'),
            new Route(path: '/web/users', handler: 'fn', methods: [Method::GET], name: 'web.users.index'),
        ];

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn($routes);

        $command = new DebugRoutesCommand($router);
        $input = new ArrayInput(arguments: [], options: ['name' => 'api']);
        $output = new BufferedOutput();

        $command->execute($input, $output);

        self::assertStringContainsString('api.users.index', $output->buffer);
        self::assertStringNotContainsString('web.users.index', $output->buffer);
    }

    #[Test]
    public function it_shows_method_count_summary(): void
    {
        $routes = [
            new Route(path: '/a', handler: 'fn', methods: [Method::GET]),
            new Route(path: '/b', handler: 'fn', methods: [Method::GET]),
            new Route(path: '/c', handler: 'fn', methods: [Method::POST]),
        ];

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn($routes);

        $command = new DebugRoutesCommand($router);
        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $command->execute($input, $output);

        self::assertStringContainsString('By method:', $output->buffer);
        self::assertStringContainsString('GET: 2', $output->buffer);
        self::assertStringContainsString('POST: 1', $output->buffer);
    }

    #[Test]
    public function it_shows_no_match_message_when_filter_excludes_all(): void
    {
        $routes = [
            new Route(path: '/users', handler: 'fn', methods: [Method::GET]),
        ];

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn($routes);

        $command = new DebugRoutesCommand($router);
        $input = new ArrayInput(arguments: [], options: ['path' => '/nonexistent']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('No routes match', $output->buffer);
    }
}
