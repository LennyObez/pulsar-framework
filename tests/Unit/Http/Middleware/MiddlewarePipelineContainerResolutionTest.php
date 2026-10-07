<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Container\Container;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;

/**
 * Tests that MiddlewarePipeline resolves middleware with constructor
 * dependencies via the container, falling back to direct instantiation
 * only when the class has no dependencies.
 */
#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineContainerResolutionTest extends TestCase
{
    #[Test]
    public function resolvesMiddlewareWithDependenciesViaContainer(): void
    {
        $dependency = new MiddlewareDependency('injected');

        $container = new Container();
        $container->instance(MiddlewareDependency::class, $dependency);
        $container->instance(
            MiddlewareWithDependency::class,
            new MiddlewareWithDependency($dependency),
        );

        $pipeline = new MiddlewarePipeline($container);
        $pipeline->pipe(MiddlewareWithDependency::class);

        $request = new ServerRequest(method: 'GET', uri: '/');
        $response = $pipeline->dispatch($request, fn() => Response::text('final'));

        // The middleware should have been resolved via container with its dependency
        self::assertSame('injected', $response->getHeaderLine('X-Injected'));
    }

    #[Test]
    public function resolvesMiddlewareWithoutDependenciesDirectly(): void
    {
        $container = new Container();

        $pipeline = new MiddlewarePipeline($container);
        $pipeline->pipe(MiddlewareWithoutDependencies::class);

        $request = new ServerRequest(method: 'GET', uri: '/');
        $response = $pipeline->dispatch($request, fn() => Response::text('final'));

        self::assertSame('no-deps', $response->getHeaderLine('X-Tag'));
    }

    #[Test]
    public function resolvesRegisteredMiddlewareByClassName(): void
    {
        $container = new Container();
        $container->instance(
            MiddlewareWithoutDependencies::class,
            new MiddlewareWithoutDependencies(),
        );

        $pipeline = new MiddlewarePipeline($container);
        $pipeline->pipe(MiddlewareWithoutDependencies::class);

        $request = new ServerRequest(method: 'GET', uri: '/');
        $response = $pipeline->dispatch($request, fn() => Response::text('final'));

        self::assertSame('no-deps', $response->getHeaderLine('X-Tag'));
    }

    #[Test]
    public function fallsBackToDirectInstantiationWhenContainerFails(): void
    {
        // Container that has nothing registered
        $container = new Container();

        $pipeline = new MiddlewarePipeline($container);
        // MiddlewareWithoutDependencies can be instantiated directly
        $pipeline->pipe(MiddlewareWithoutDependencies::class);

        $request = new ServerRequest(method: 'GET', uri: '/');
        $response = $pipeline->dispatch($request, fn() => Response::text('ok'));

        self::assertSame('no-deps', $response->getHeaderLine('X-Tag'));
    }

    #[Test]
    public function worksWithoutContainerForSimpleMiddleware(): void
    {
        // No container at all
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(MiddlewareWithoutDependencies::class);

        $request = new ServerRequest(method: 'GET', uri: '/');
        $response = $pipeline->dispatch($request, fn() => Response::text('ok'));

        self::assertSame('no-deps', $response->getHeaderLine('X-Tag'));
    }

    #[Test]
    public function acceptsInstanceMiddleware(): void
    {
        $middleware = new MiddlewareWithDependency(new MiddlewareDependency('direct'));

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe($middleware);

        $request = new ServerRequest(method: 'GET', uri: '/');
        $response = $pipeline->dispatch($request, fn() => Response::text('ok'));

        self::assertSame('direct', $response->getHeaderLine('X-Injected'));
    }
}

/**
 * Value object used as a constructor dependency for MiddlewareWithDependency.
 */
final readonly class MiddlewareDependency
{
    public function __construct(
        public string $value,
    ) {}
}

/**
 * Middleware that requires a constructor dependency.
 * Cannot be instantiated with `new $class()`.
 */
final readonly class MiddlewareWithDependency implements MiddlewareInterface
{
    public function __construct(
        private MiddlewareDependency $dependency,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response->withHeader('X-Injected', $this->dependency->value);
    }
}

/**
 * Middleware with no constructor dependencies.
 * Can be instantiated with `new $class()`.
 */
final class MiddlewareWithoutDependencies implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response->withHeader('X-Tag', 'no-deps');
    }
}
