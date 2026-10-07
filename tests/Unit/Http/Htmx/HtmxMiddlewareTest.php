<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Htmx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Htmx\HtmxMiddleware;
use Pulsar\Http\Htmx\HtmxRequest;

#[CoversClass(HtmxMiddleware::class)]
final class HtmxMiddlewareTest extends TestCase
{
    #[Test]
    public function attaches_htmx_request_attribute(): void
    {
        $middleware = new HtmxMiddleware();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnCallback(
            fn(string $name) => $name === 'PX-Request',
        );

        $captured = null;
        $request->method('withAttribute')->willReturnCallback(
            function (string $name, mixed $value) use ($request, &$captured) {
                if ($name === 'htmx') {
                    $captured = $value;
                }

                return $request;
            },
        );

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withAddedHeader')->willReturn($response);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

        self::assertInstanceOf(HtmxRequest::class, $captured);
    }

    #[Test]
    public function adds_vary_header_for_htmx_requests(): void
    {
        $middleware = new HtmxMiddleware();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnCallback(
            fn(string $name) => $name === 'PX-Request',
        );
        $request->method('withAttribute')->willReturn($request);

        $response = $this->createStub(ResponseInterface::class);

        $varyAdded = false;
        $response->method('withAddedHeader')->willReturnCallback(
            function (string $name, string $value) use ($response, &$varyAdded) {
                if ($name === 'Vary' && $value === 'PX-Request') {
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
    public function does_not_add_vary_for_non_htmx_requests(): void
    {
        $middleware = new HtmxMiddleware();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturn(false);
        $request->method('withAttribute')->willReturn($request);

        $response = $this->createStub(ResponseInterface::class);

        $varyAdded = false;
        $response->method('withAddedHeader')->willReturnCallback(
            function () use ($response, &$varyAdded) {
                $varyAdded = true;

                return $response;
            },
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

        self::assertFalse($varyAdded);
    }

    #[Test]
    public function accepts_custom_config(): void
    {
        $middleware = new HtmxMiddleware();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturn(false);
        $request->method('withAttribute')->willReturn($request);

        $response = $this->createStub(ResponseInterface::class);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame($response, $result);
    }
}
