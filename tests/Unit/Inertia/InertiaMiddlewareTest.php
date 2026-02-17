<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Inertia;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Inertia\InertiaConfig;
use Pulsar\Inertia\InertiaMiddleware;

#[CoversClass(InertiaMiddleware::class)]
final class InertiaMiddlewareTest extends TestCase
{
    #[Test]
    public function inertia_attribute_set_for_inertia_requests(): void
    {
        $middleware = new InertiaMiddleware();

        $captured = [];
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnCallback(
            fn(string $name) => $name === 'X-Inertia',
        );
        $request->method('getHeaderLine')->willReturn('');
        $request->method('withAttribute')->willReturnCallback(
            function (string $name, mixed $value) use ($request, &$captured) {
                $captured[$name] = $value;

                return $request;
            },
        );

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withAddedHeader')->willReturn($response);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

        self::assertTrue($captured['inertia']);
    }

    #[Test]
    public function non_inertia_request_sets_false(): void
    {
        $middleware = new InertiaMiddleware();

        $captured = [];
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturn(false);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('withAttribute')->willReturnCallback(
            function (string $name, mixed $value) use ($request, &$captured) {
                $captured[$name] = $value;

                return $request;
            },
        );

        $response = $this->createStub(ResponseInterface::class);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

        self::assertFalse($captured['inertia']);
    }

    #[Test]
    public function shared_props_injected_into_request(): void
    {
        $middleware = new InertiaMiddleware();
        $middleware->share(['auth' => ['user' => 'Test']]);

        $captured = [];
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturn(false);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('withAttribute')->willReturnCallback(
            function (string $name, mixed $value) use ($request, &$captured) {
                $captured[$name] = $value;

                return $request;
            },
        );

        $response = $this->createStub(ResponseInterface::class);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

        self::assertSame(['auth' => ['user' => 'Test']], $captured['inertia_shared_props']);
    }

    #[Test]
    public function vary_header_added_for_inertia_requests(): void
    {
        $middleware = new InertiaMiddleware();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnCallback(
            fn(string $name) => $name === 'X-Inertia',
        );
        $request->method('getHeaderLine')->willReturn('');
        $request->method('withAttribute')->willReturn($request);

        $response = $this->createStub(ResponseInterface::class);

        $varyAdded = false;
        $response->method('withAddedHeader')->willReturnCallback(
            function (string $name, string $value) use ($response, &$varyAdded) {
                if ($name === 'Vary' && $value === 'X-Inertia') {
                    $varyAdded = true;
                }

                return $response;
            },
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

        self::assertTrue($varyAdded);
    }

    #[Test]
    public function version_mismatch_returns_409(): void
    {
        $conflictResponse = $this->createStub(ResponseInterface::class);
        $conflictResponse->method('withHeader')->willReturn($conflictResponse);

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($conflictResponse);

        $middleware = new InertiaMiddleware(
            config: new InertiaConfig(),
            assetVersion: 'v2',
            responseFactory: $responseFactory,
        );

        $uri = $this->createStub(UriInterface::class);
        $uri->method('__toString')->willReturn('https://example.com/page');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnCallback(
            fn(string $name) => $name === 'X-Inertia',
        );
        $request->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => match ($name) {
                'X-Inertia-Version' => 'v1',
                default => '',
            },
        );
        $request->method('withAttribute')->willReturn($request);
        $request->method('getUri')->willReturn($uri);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        self::assertSame($conflictResponse, $result);
    }
}
