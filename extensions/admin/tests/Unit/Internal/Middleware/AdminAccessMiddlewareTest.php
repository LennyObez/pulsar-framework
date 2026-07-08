<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Middleware\AdminAccessMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

#[CoversClass(AdminAccessMiddleware::class)]
final class AdminAccessMiddlewareTest extends TestCase
{
    private static function makeRequest(): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/admin/dashboard',
        );
    }

    private static function makeHandler(Response $response): RequestHandlerInterface
    {
        $handler = new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly Response $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return $handler;
    }

    #[Test]
    public function allowsRequestWhenEnabled(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);
        $middleware = new AdminAccessMiddleware($config);

        $expectedResponse = Response::json(['status' => 'ok']);
        $handler = self::makeHandler($expectedResponse);

        $response = $middleware->process(self::makeRequest(), $handler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function blocksRequestWhenDisabled(): void
    {
        $config = AdminConfig::fromArray(['enabled' => false]);
        $middleware = new AdminAccessMiddleware($config);

        $handler = self::makeHandler(Response::json(['status' => 'ok']));

        $response = $middleware->process(self::makeRequest(), $handler);

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
        self::assertStringContainsString('disabled', (string) $response->getBody());
    }

    #[Test]
    public function doesNotCallNextWhenDisabled(): void
    {
        $config = AdminConfig::fromArray(['enabled' => false]);
        $middleware = new AdminAccessMiddleware($config);

        $called = false;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $middleware->process(self::makeRequest(), $handler);
    }

    #[Test]
    public function passesRequestToNextWhenEnabled(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);
        $middleware = new AdminAccessMiddleware($config);

        $receivedRequest = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$receivedRequest): ResponseInterface {
                $receivedRequest = $req;
                return Response::json(['status' => 'ok']);
            },
        );

        $request = self::makeRequest();
        $middleware->process($request, $handler);

        self::assertSame($request, $receivedRequest);
    }

    #[Test]
    public function defaultConfigIsDisabled(): void
    {
        $config = AdminConfig::fromArray([]);
        $middleware = new AdminAccessMiddleware($config);

        $handler = self::makeHandler(Response::json(['status' => 'ok']));

        $response = $middleware->process(self::makeRequest(), $handler);

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
    }

    #[Test]
    public function blocksRequestWithErrorBodyWhenDisabled(): void
    {
        $config = AdminConfig::fromArray(['enabled' => false]);
        $middleware = new AdminAccessMiddleware($config);
        $handler = self::makeHandler(Response::json([]));

        $response = $middleware->process(self::makeRequest(), $handler);
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(['error' => 'Admin panel is disabled'], $body);
    }
}
