<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;

#[CoversClass(Kernel::class)]
final class KernelAutowireTest extends TestCase
{
    #[Test]
    public function controllerWithNoDependenciesIsInstantiated(): void
    {
        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/no-deps',
            handler: [NoDepsController::class, 'handle'],
        ));

        $request = new ServerRequest(method: 'GET', uri: '/no-deps');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-deps-ok', (string) $response->getBody());
    }

    #[Test]
    public function controllerWithContainerDependencyIsAutowired(): void
    {
        $kernel = new Kernel();

        // Register a service that the controller depends on
        $kernel->container()->instance(GreeterService::class, new GreeterService());

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/autowired',
            handler: [AutowiredController::class, 'handle'],
        ));

        $request = new ServerRequest(method: 'GET', uri: '/autowired');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Hello from greeter', (string) $response->getBody());
    }

    #[Test]
    public function controllerWithDefaultParameterUsesDefault(): void
    {
        $kernel = new Kernel();

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/default-param',
            handler: [DefaultParamController::class, 'handle'],
        ));

        $request = new ServerRequest(method: 'GET', uri: '/default-param');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('default-value', (string) $response->getBody());
    }

    #[Test]
    public function controllerWithNullableParamGetsNull(): void
    {
        $kernel = new Kernel();

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/nullable',
            handler: [NullableParamController::class, 'handle'],
        ));

        $request = new ServerRequest(method: 'GET', uri: '/nullable');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('null-ok', (string) $response->getBody());
    }

    #[Test]
    public function controllerWithUnresolvableParamReturnsServerError(): void
    {
        $kernel = new Kernel();

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/unresolvable',
            handler: [UnresolvableController::class, 'handle'],
        ));

        $request = new ServerRequest(method: 'GET', uri: '/unresolvable');

        // No exception handler is wired, so the kernel renders a generic 500
        // (F4.11) instead of leaking the resolver's RoutingException to the
        // SAPI. The RoutingException itself is asserted directly against the
        // resolver in ReflectionControllerResolverTest.
        $response = $kernel->handle($request);

        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function controllerRegisteredInContainerTakesPrecedence(): void
    {
        $kernel = new Kernel();

        // Register a specific instance
        $controller = new NoDepsController();
        $kernel->container()->instance(NoDepsController::class, $controller);

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/registered',
            handler: [NoDepsController::class, 'handle'],
        ));

        $request = new ServerRequest(method: 'GET', uri: '/registered');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function controllerWithMultipleDependenciesIsAutowired(): void
    {
        // Arrange
        $kernel = new Kernel();
        $greeter = new GreeterService();
        $formatter = new FormatterService();
        $kernel->container()->instance(GreeterService::class, $greeter);
        $kernel->container()->instance(FormatterService::class, $formatter);

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/multi-deps',
            handler: [MultiDepsController::class, 'handle'],
        ));

        // Act
        $request = new ServerRequest(method: 'GET', uri: '/multi-deps');
        $response = $kernel->handle($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('HELLO FROM GREETER', (string) $response->getBody());
    }

    #[Test]
    public function invokableControllerWithDependencyIsAutowired(): void
    {
        // Arrange
        $kernel = new Kernel();
        $kernel->container()->instance(GreeterService::class, new GreeterService());

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/invokable',
            handler: InvokableAutowiredController::class,
        ));

        // Act
        $request = new ServerRequest(method: 'GET', uri: '/invokable');
        $response = $kernel->handle($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('invokable:Hello from greeter', (string) $response->getBody());
    }
}

// --- Test doubles (minimal controller classes) ---

final class GreeterService
{
    public function greet(): string
    {
        return 'Hello from greeter';
    }
}

final class FormatterService
{
    public function upper(string $text): string
    {
        return strtoupper($text);
    }
}

final class NoDepsController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::text('no-deps-ok');
    }
}

final class AutowiredController
{
    public function __construct(
        private readonly GreeterService $greeter,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::text($this->greeter->greet());
    }
}

final class MultiDepsController
{
    public function __construct(
        private readonly GreeterService $greeter,
        private readonly FormatterService $formatter,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::text($this->formatter->upper($this->greeter->greet()));
    }
}

final class InvokableAutowiredController
{
    public function __construct(
        private readonly GreeterService $greeter,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return Response::text('invokable:' . $this->greeter->greet());
    }
}

final class DefaultParamController
{
    public function __construct(
        private readonly string $value = 'default-value',
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::text($this->value);
    }
}

interface NullableServiceContract {}

final class NullableParamController
{
    public function __construct(
        // An unbound interface cannot be autowired, so the nullable parameter
        // falls back to null (a nullable concrete would now be autowired).
        private readonly ?NullableServiceContract $greeter = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::text($this->greeter === null ? 'null-ok' : 'has-greeter');
    }
}

final class UnresolvableController
{
    public function __construct(
        private readonly string $requiredString,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::text($this->requiredString);
    }
}
