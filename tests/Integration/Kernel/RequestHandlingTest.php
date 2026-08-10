<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Container\Container;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\Router;

#[CoversClass(Kernel::class)]
final class RequestHandlingTest extends TestCase
{
    private function createRequest(
        string $method = 'GET',
        string $path = '/',
    ): ServerRequest {
        return new ServerRequest(
            method: $method,
            uri: $path,
        );
    }

    #[Test]
    public function kernelHandlesRequestWithCallableHandler(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('Hello, World!'));

        $response = $kernel->handle($this->createRequest());

        self::assertSame('Hello, World!', (string) $response->getBody());
        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function kernelHandlesRequestWithStringReturn(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => '<h1>Hello</h1>');

        $response = $kernel->handle($this->createRequest());

        self::assertSame('<h1>Hello</h1>', (string) $response->getBody());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function kernelHandlesRequestWithControllerArray(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', [TestController::class, 'index']);

        $response = $kernel->handle($this->createRequest());

        self::assertSame('controller response', (string) $response->getBody());
    }

    #[Test]
    public function kernelHandlesRequestWithInvokableController(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', InvokableController::class);

        $response = $kernel->handle($this->createRequest());

        self::assertSame('invokable response', (string) $response->getBody());
    }

    #[Test]
    public function kernelResolvesControllerFromContainer(): void
    {
        $container = new Container();
        $controller = new TestController();
        $container->instance(TestController::class, $controller);

        $kernel = new Kernel($container);
        $kernel->router()->get('/', [TestController::class, 'index']);

        $response = $kernel->handle($this->createRequest());

        self::assertSame('controller response', (string) $response->getBody());
    }

    #[Test]
    public function kernelPassesRouteParametersToHandler(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/users/{id}', function (ServerRequestInterface $request, array $params) {
            return Response::json(['id' => $params['id']]);
        });

        $request = $this->createRequest(path: '/users/42');
        $response = $kernel->handle($request);

        self::assertStringContainsString('"id":"42"', (string) $response->getBody());
    }

    #[Test]
    public function kernelAddsRouteParametersToRequestAttributes(): void
    {
        $kernel = new Kernel();
        $receivedId = null;

        $kernel->router()->get('/users/{id}', function (ServerRequestInterface $request) use (&$receivedId) {
            $receivedId = $request->getAttribute('id');
            return Response::text('ok');
        });

        $kernel->handle($this->createRequest(path: '/users/123'));

        self::assertSame('123', $receivedId);
    }

    #[Test]
    public function kernelReturns404ForUnknownRouteWithoutErrorHandler(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('home'));

        // With no exception handler wired the kernel renders a generic
        // status-correct page rather than leaking the RoutingException.
        $response = $kernel->handle($this->createRequest(path: '/unknown'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function kernelReturns405ForMethodNotAllowedWithoutErrorHandler(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/test', fn() => Response::text('ok'));

        $response = $kernel->handle($this->createRequest('POST', '/test'));

        self::assertSame(405, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('Allow'));
    }

    #[Test]
    public function kernelAppliesGlobalMiddleware(): void
    {
        $kernel = new Kernel();
        $kernel->addMiddleware(new AddHeaderMiddleware('X-Global', 'true'));
        $kernel->router()->get('/', fn() => Response::text('ok'));

        $response = $kernel->handle($this->createRequest());

        self::assertSame('true', $response->getHeaderLine('X-Global'));
    }

    #[Test]
    public function kernelAppliesMultipleMiddlewareInOrder(): void
    {
        $kernel = new Kernel();
        $kernel->addMiddleware(new AddHeaderMiddleware('X-First', '1'));
        $kernel->addMiddleware(new AddHeaderMiddleware('X-Second', '2'));
        $kernel->router()->get('/', fn() => Response::text('ok'));

        $response = $kernel->handle($this->createRequest());

        self::assertSame('1', $response->getHeaderLine('X-First'));
        self::assertSame('2', $response->getHeaderLine('X-Second'));
    }

    #[Test]
    public function kernelBootsOnFirstRequest(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('ok'));

        self::assertFalse($kernel->booted);

        $kernel->handle($this->createRequest());

        self::assertTrue($kernel->booted);
    }

    #[Test]
    public function kernelProvidesContainerAccess(): void
    {
        $kernel = new Kernel();

        $container = $kernel->container();

        self::assertTrue($container->has(Kernel::class));
        self::assertTrue($container->has(Router::class));
        self::assertSame($kernel, $container->get(Kernel::class));
    }

    #[Test]
    public function kernelProvidesRouterAccess(): void
    {
        $kernel = new Kernel();

        $router = $kernel->router();

        self::assertInstanceOf(Router::class, $router);
    }

    #[Test]
    public function kernelCanBeShutdown(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('ok'));
        $kernel->handle($this->createRequest());

        self::assertTrue($kernel->booted);

        $kernel->shutdown();

        self::assertFalse($kernel->booted);
    }
}

class TestController
{
    /**
     * @param array<string, string> $params
     */
    public function index(ServerRequestInterface $request, array $params): Response
    {
        return Response::text('controller response');
    }
}

class InvokableController
{
    /**
     * @param array<string, string> $params
     */
    public function __invoke(ServerRequestInterface $request, array $params): Response
    {
        return Response::text('invokable response');
    }
}

class AddHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $value,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        return $response->withHeader($this->name, $this->value);
    }
}
