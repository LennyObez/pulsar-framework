<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Core\Kernel;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\Router;

#[CoversClass(Kernel::class)]
final class RequestHandlingTest extends TestCase
{
    private function createRequest(
        Method $method = Method::GET,
        string $path = '/',
    ): Request {
        return new Request(
            method: $method,
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    #[Test]
    public function kernelHandlesRequestWithCallableHandler(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('Hello, World!'));

        $response = $kernel->handle($this->createRequest());

        self::assertSame('Hello, World!', $response->body);
        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function kernelHandlesRequestWithStringReturn(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => '<h1>Hello</h1>');

        $response = $kernel->handle($this->createRequest());

        self::assertSame('<h1>Hello</h1>', $response->body);
        self::assertSame('text/html; charset=utf-8', $response->headers->first('Content-Type'));
    }

    #[Test]
    public function kernelHandlesRequestWithControllerArray(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', [TestController::class, 'index']);

        $response = $kernel->handle($this->createRequest());

        self::assertSame('controller response', $response->body);
    }

    #[Test]
    public function kernelHandlesRequestWithInvokableController(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', InvokableController::class);

        $response = $kernel->handle($this->createRequest());

        self::assertSame('invokable response', $response->body);
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

        self::assertSame('controller response', $response->body);
    }

    #[Test]
    public function kernelPassesRouteParametersToHandler(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/users/{id}', function (Request $request, array $params) {
            return Response::json(['id' => $params['id']]);
        });

        $request = $this->createRequest(path: '/users/42');
        $response = $kernel->handle($request);

        self::assertStringContainsString('"id":"42"', $response->body);
    }

    #[Test]
    public function kernelAddsRouteParametersToRequestAttributes(): void
    {
        $kernel = new Kernel();
        $receivedId = null;

        $kernel->router()->get('/users/{id}', function (Request $request) use (&$receivedId) {
            $receivedId = $request->attribute('id');
            return Response::text('ok');
        });

        $kernel->handle($this->createRequest(path: '/users/123'));

        self::assertSame('123', $receivedId);
    }

    #[Test]
    public function kernelReturns404ForUnknownRoute(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('home'));

        $response = $kernel->handle($this->createRequest(path: '/unknown'));

        self::assertSame(ResponseStatus::NotFound, $response->status);
        self::assertStringContainsString('404', $response->body);
    }

    #[Test]
    public function kernelReturns405ForMethodNotAllowed(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/test', fn() => Response::text('ok'));

        $response = $kernel->handle($this->createRequest(Method::POST, '/test'));

        self::assertSame(ResponseStatus::MethodNotAllowed, $response->status);
        self::assertNotNull($response->headers->first('Allow'));
    }

    #[Test]
    public function kernelAppliesGlobalMiddleware(): void
    {
        $kernel = new Kernel();
        $kernel->addMiddleware(new AddHeaderMiddleware('X-Global', 'true'));
        $kernel->router()->get('/', fn() => Response::text('ok'));

        $response = $kernel->handle($this->createRequest());

        self::assertSame('true', $response->headers->first('X-Global'));
    }

    #[Test]
    public function kernelAppliesMultipleMiddlewareInOrder(): void
    {
        $kernel = new Kernel();
        $kernel->addMiddleware(new AddHeaderMiddleware('X-First', '1'));
        $kernel->addMiddleware(new AddHeaderMiddleware('X-Second', '2'));
        $kernel->router()->get('/', fn() => Response::text('ok'));

        $response = $kernel->handle($this->createRequest());

        self::assertSame('1', $response->headers->first('X-First'));
        self::assertSame('2', $response->headers->first('X-Second'));
    }

    #[Test]
    public function kernelBootsOnFirstRequest(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/', fn() => Response::text('ok'));

        self::assertFalse($kernel->isBooted());

        $kernel->handle($this->createRequest());

        self::assertTrue($kernel->isBooted());
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

        self::assertTrue($kernel->isBooted());

        $kernel->shutdown();

        self::assertFalse($kernel->isBooted());
    }
}

class TestController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return Response::text('controller response');
    }
}

class InvokableController
{
    /**
     * @param array<string, string> $params
     */
    public function __invoke(Request $request, array $params): Response
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

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);
        return $response->withHeader($this->name, $this->value);
    }
}
