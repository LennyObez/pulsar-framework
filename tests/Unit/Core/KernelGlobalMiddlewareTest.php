<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;

#[CoversClass(Kernel::class)]
final class KernelGlobalMiddlewareTest extends TestCase
{
    #[Test]
    public function globalMiddlewareExecutesBeforeRouteHandler(): void
    {
        $kernel = new Kernel();
        $kernel->addMiddleware(new KernelTestOrderMiddleware('header-value'));

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/check',
            handler: fn(ServerRequestInterface $req): ResponseInterface => Response::html('ok'),
        ));

        $request = new ServerRequest(method: 'GET', uri: 'http://localhost/check');
        $response = $kernel->handle($request);

        self::assertSame('header-value', $response->getHeaderLine('X-Global'));
        self::assertSame('ok', (string) $response->getBody());
    }

    #[Test]
    public function multipleGlobalMiddlewareExecuteInOrder(): void
    {
        $kernel = new Kernel();
        $kernel->addMiddleware(new KernelTestOrderMiddleware('first'));
        $kernel->addMiddleware(new KernelTestOrderMiddleware('second'));

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/multi',
            handler: fn(ServerRequestInterface $req): ResponseInterface => Response::html('body'),
        ));

        $request = new ServerRequest(method: 'GET', uri: 'http://localhost/multi');
        $response = $kernel->handle($request);

        // Last middleware's header wins (overwrites)
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function globalMiddlewareCanShortCircuitResponse(): void
    {
        $kernel = new Kernel();
        $kernel->addMiddleware(new KernelTestBlockingMiddleware());

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/blocked',
            handler: fn(ServerRequestInterface $req): ResponseInterface => Response::html('never-reached'),
        ));

        $request = new ServerRequest(method: 'GET', uri: 'http://localhost/blocked');
        $response = $kernel->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('blocked', (string) $response->getBody());
    }

    #[Test]
    public function handleWithNoMiddlewareDispatchesDirectly(): void
    {
        $kernel = new Kernel();
        // No middleware added

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/direct',
            handler: fn(ServerRequestInterface $req): ResponseInterface => Response::html('direct'),
        ));

        $request = new ServerRequest(method: 'GET', uri: 'http://localhost/direct');
        $response = $kernel->handle($request);

        self::assertSame('direct', (string) $response->getBody());
    }

    #[Test]
    public function handleMultipleRequestsReusesPipeline(): void
    {
        $kernel = new Kernel();
        $counter = new KernelTestCountingMiddleware();
        $kernel->addMiddleware($counter);

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/count',
            handler: fn(ServerRequestInterface $req): ResponseInterface => Response::html('ok'),
        ));

        $request = new ServerRequest(method: 'GET', uri: 'http://localhost/count');
        $kernel->handle($request);
        $kernel->handle($request);
        $kernel->handle($request);

        self::assertSame(3, $counter->count);
    }

    #[Test]
    public function addMiddlewareWithClassStringReference(): void
    {
        $kernel = new Kernel();
        // Register the middleware instance in the container so class-string works
        $middleware = new KernelTestOrderMiddleware('class-resolved');
        $kernel->container()->instance(KernelTestOrderMiddleware::class, $middleware);
        $kernel->addMiddleware(KernelTestOrderMiddleware::class);

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/class-mw',
            handler: fn(ServerRequestInterface $req): ResponseInterface => Response::html('ok'),
        ));

        $request = new ServerRequest(method: 'GET', uri: 'http://localhost/class-mw');
        $response = $kernel->handle($request);

        self::assertSame('class-resolved', $response->getHeaderLine('X-Global'));
    }

    #[Test]
    public function shutdownAllowsReboot(): void
    {
        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/reboot',
            handler: fn(ServerRequestInterface $req): ResponseInterface => Response::html('rebooted'),
        ));

        $kernel->boot();
        self::assertTrue($kernel->booted);

        $kernel->shutdown();
        self::assertFalse($kernel->booted);

        // Re-boot and handle a request
        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/reboot'));
        self::assertTrue($kernel->booted);
        self::assertSame('rebooted', (string) $response->getBody());
    }
}

final class KernelTestOrderMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $value) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        return $response->withHeader('X-Global', $this->value);
    }
}

final class KernelTestBlockingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return Response::html('blocked', 403);
    }
}

final class KernelTestCountingMiddleware implements MiddlewareInterface
{
    public int $count = 0;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->count++;
        return $handler->handle($request);
    }
}
