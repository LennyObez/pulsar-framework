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
use Pulsar\Core\MicroKernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use stdClass;

#[CoversClass(MicroKernel::class)]
final class MicroKernelEdgeCaseTest extends TestCase
{
    #[Test]
    public function bindRegistersObjectInstance(): void
    {
        $app = MicroKernel::create();
        $service = new stdClass();
        $service->name = 'test-service';

        $app->bind('my.service', $service);

        $resolved = $app->container()->get('my.service');

        self::assertSame($service, $resolved);
    }

    #[Test]
    public function bindRegistersClassString(): void
    {
        $app = MicroKernel::create();
        $app->bind(stdClass::class, stdClass::class);

        $resolved = $app->container()->get(stdClass::class);

        self::assertInstanceOf(stdClass::class, $resolved);
    }

    #[Test]
    public function useAddsGlobalMiddleware(): void
    {
        $app = MicroKernel::create();
        $app->get('/mid-test', fn() => 'original');

        $app->use(new MicroKernelTestHeaderMiddleware());

        $request = new ServerRequest(method: 'GET', uri: 'http://localhost/mid-test');
        $response = $app->handle($request);

        self::assertSame('middleware-ran', $response->getHeaderLine('X-Middleware'));
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function handleBootsOnlyOnce(): void
    {
        $app = MicroKernel::create();
        $counter = new class {
            public int $calls = 0;
        };

        $app->get('/a', function () use ($counter) {
            $counter->calls++;
            return 'a';
        });
        $app->get('/b', function () use ($counter) {
            $counter->calls++;
            return 'b';
        });

        $app->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/a'));
        $app->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/b'));

        self::assertSame(2, $counter->calls);
    }

    #[Test]
    public function scalarResponseConvertsToHtml(): void
    {
        $app = MicroKernel::create();
        $app->get('/number', fn() => 42);

        $request = new ServerRequest(method: 'GET', uri: 'http://localhost/number');
        $response = $app->handle($request);

        // Scalar (non-string, non-array, non-Response) should be converted
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function namedRouteRegistration(): void
    {
        $app = MicroKernel::create();
        $app->get('/named', fn() => 'found', 'test.route');

        $request = new ServerRequest(method: 'GET', uri: 'http://localhost/named');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function postRouteWithNamedRoute(): void
    {
        $app = MicroKernel::create();
        $app->post('/data', fn() => Response::json(['ok' => true]), 'data.store');

        $request = new ServerRequest(method: 'POST', uri: 'http://localhost/data');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function putRouteWithName(): void
    {
        $app = MicroKernel::create();
        $app->put('/item/{id}', fn(ServerRequestInterface $r, string $id) => "Updated $id", 'item.update');

        $response = $app->handle(new ServerRequest(method: 'PUT', uri: 'http://localhost/item/5'));

        self::assertStringContainsString('Updated 5', (string) $response->getBody());
    }

    #[Test]
    public function patchRouteWithName(): void
    {
        $app = MicroKernel::create();
        $app->patch('/item/{id}', fn(ServerRequestInterface $r, string $id) => "Patched $id", 'item.patch');

        $response = $app->handle(new ServerRequest(method: 'PATCH', uri: 'http://localhost/item/3'));

        self::assertStringContainsString('Patched 3', (string) $response->getBody());
    }

    #[Test]
    public function deleteRouteWithName(): void
    {
        $app = MicroKernel::create();
        $app->delete('/item/{id}', fn(ServerRequestInterface $r, string $id) => "Deleted $id", 'item.delete');

        $response = $app->handle(new ServerRequest(method: 'DELETE', uri: 'http://localhost/item/8'));

        self::assertStringContainsString('Deleted 8', (string) $response->getBody());
    }

    #[Test]
    public function nestedRouteGroups(): void
    {
        $app = MicroKernel::create();
        $app->group('/api', function (\Pulsar\Routing\Router $router) {
            $router->group('/v1', function (\Pulsar\Routing\Router $r) {
                $r->get('/users', fn() => 'v1-users');
            });
        });

        $response = $app->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/api/v1/users'));

        self::assertStringContainsString('v1-users', (string) $response->getBody());
    }

    #[Test]
    public function containerReturnsContainerInterface(): void
    {
        $app = MicroKernel::create();

        self::assertInstanceOf(\Pulsar\Container\ContainerInterface::class, $app->container());
    }

    #[Test]
    public function notFoundReturns404(): void
    {
        $app = MicroKernel::create();
        $app->get('/exists', fn() => 'ok');

        $response = $app->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/missing'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function methodChainingWorksForAllMethods(): void
    {
        $app = MicroKernel::create();

        $result = $app
            ->get('/a', fn() => 'a')
            ->post('/b', fn() => 'b')
            ->put('/c', fn() => 'c')
            ->patch('/d', fn() => 'd')
            ->delete('/e', fn() => 'e');

        self::assertInstanceOf(MicroKernel::class, $result);
    }

    #[Test]
    public function useReturnsMicroKernelForChaining(): void
    {
        $app = MicroKernel::create();

        $result = $app->use(new MicroKernelTestHeaderMiddleware());

        self::assertInstanceOf(MicroKernel::class, $result);
    }

    #[Test]
    public function bindReturnsMicroKernelForChaining(): void
    {
        $app = MicroKernel::create();

        $result = $app->bind('foo', fn() => new stdClass());

        self::assertInstanceOf(MicroKernel::class, $result);
    }

    #[Test]
    public function groupReturnsMicroKernelForChaining(): void
    {
        $app = MicroKernel::create();

        $result = $app->group('/api', function () {});

        self::assertInstanceOf(MicroKernel::class, $result);
    }
}

final class MicroKernelTestHeaderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response->withHeader('X-Middleware', 'middleware-ran');
    }
}
