<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Core\MicroKernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use stdClass;

use function json_decode;

#[CoversClass(MicroKernel::class)]
final class MicroKernelTest extends TestCase
{
    private MicroKernel $app;

    protected function setUp(): void
    {
        $this->app = MicroKernel::create();
    }

    public function testGetRouteReturnsStringResponse(): void
    {
        $this->app->get('/hello', fn() => 'Hello, World!');

        $request = $this->createRequest('GET', '/hello');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hello, World!', (string) $response->getBody());
    }

    public function testGetRouteWithParameter(): void
    {
        $this->app->get('/hello/{name}', fn(ServerRequestInterface $r, string $name) => "Hello, $name!");

        $request = $this->createRequest('GET', '/hello/Alice');
        $response = $this->app->handle($request);

        self::assertStringContainsString('Hello, Alice!', (string) $response->getBody());
    }

    public function testPostRoute(): void
    {
        $this->app->post('/api/data', fn() => Response::json(['ok' => true]));

        $request = $this->createRequest('POST', '/api/data');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['ok']);
    }

    public function testPutRoute(): void
    {
        $this->app->put('/api/item/{id}', fn(ServerRequestInterface $r, string $id) => "Updated $id");

        $request = $this->createRequest('PUT', '/api/item/42');
        $response = $this->app->handle($request);

        self::assertStringContainsString('Updated 42', (string) $response->getBody());
    }

    public function testPatchRoute(): void
    {
        $this->app->patch('/api/item/{id}', fn(ServerRequestInterface $r, string $id) => "Patched $id");

        $request = $this->createRequest('PATCH', '/api/item/99');
        $response = $this->app->handle($request);

        self::assertStringContainsString('Patched 99', (string) $response->getBody());
    }

    public function testDeleteRoute(): void
    {
        $this->app->delete('/api/item/{id}', fn(ServerRequestInterface $r, string $id) => "Deleted $id");

        $request = $this->createRequest('DELETE', '/api/item/7');
        $response = $this->app->handle($request);

        self::assertStringContainsString('Deleted 7', (string) $response->getBody());
    }

    public function testRouteGroupWithPrefix(): void
    {
        $this->app->group('/api', function (\Pulsar\Routing\Router $router) {
            $router->get('/users', fn() => 'users list');
            $router->get('/posts', fn() => 'posts list');
        });

        $request = $this->createRequest('GET', '/api/users');
        $response = $this->app->handle($request);

        self::assertStringContainsString('users list', (string) $response->getBody());
    }

    public function testNotFoundReturns404Json(): void
    {
        $this->app->get('/exists', fn() => 'ok');

        $request = $this->createRequest('GET', '/nonexistent');
        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testArrayReturnConvertsToJson(): void
    {
        $this->app->get('/api/status', fn() => ['status' => 'ok', 'version' => '1.0']);

        $request = $this->createRequest('GET', '/api/status');
        $response = $this->app->handle($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $body['status']);
    }

    public function testResponseObjectPassesThrough(): void
    {
        $this->app->get('/custom', fn() => Response::json(['custom' => true], 201));

        $request = $this->createRequest('GET', '/custom');
        $response = $this->app->handle($request);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testBindRegistersClosureInContainer(): void
    {
        $this->app->bind('greeting', fn() => new stdClass());

        $container = $this->app->container();
        $result = $container->get('greeting');

        self::assertInstanceOf(stdClass::class, $result);
    }

    public function testContainerReturnsInstance(): void
    {
        $container = $this->app->container();

        self::assertInstanceOf(\Pulsar\Container\ContainerInterface::class, $container);
    }

    public function testMultipleRequestsWorkWithSameKernel(): void
    {
        $this->app->get('/a', fn() => 'route a');
        $this->app->get('/b', fn() => 'route b');

        $responseA = $this->app->handle($this->createRequest('GET', '/a'));
        $responseB = $this->app->handle($this->createRequest('GET', '/b'));

        self::assertStringContainsString('route a', (string) $responseA->getBody());
        self::assertStringContainsString('route b', (string) $responseB->getBody());
    }

    private function createRequest(string $method, string $path): ServerRequestInterface
    {
        return new ServerRequest(
            method: $method,
            uri: "http://localhost$path",
        );
    }
}
