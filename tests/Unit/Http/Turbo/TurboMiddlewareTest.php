<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Turbo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Turbo\TurboMiddleware;

#[CoversClass(TurboMiddleware::class)]
final class TurboMiddlewareTest extends TestCase
{
    #[Test]
    public function detects_turbo_frame_request(): void
    {
        $middleware = new TurboMiddleware();

        $captured = [];
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => match ($name) {
                'Turbo-Frame' => 'comments',
                'Accept' => 'text/html',
                default => '',
            },
        );
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

        self::assertSame('comments', $captured['turbo_frame']);
    }

    #[Test]
    public function non_frame_request_sets_null(): void
    {
        $middleware = new TurboMiddleware();

        $captured = [];
        $request = $this->createStub(ServerRequestInterface::class);
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

        self::assertNull($captured['turbo_frame']);
    }

    #[Test]
    public function detects_turbo_stream_accept(): void
    {
        $middleware = new TurboMiddleware();

        $captured = [];
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => match ($name) {
                'Turbo-Frame' => '',
                'Accept' => 'text/vnd.turbo-stream.html, text/html',
                default => '',
            },
        );
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

        self::assertTrue($captured['turbo_stream']);
    }

    #[Test]
    public function vary_header_added_for_frame_requests(): void
    {
        $middleware = new TurboMiddleware();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => match ($name) {
                'Turbo-Frame' => 'main-frame',
                default => '',
            },
        );
        $request->method('withAttribute')->willReturn($request);

        $response = $this->createStub(ResponseInterface::class);

        $varyAdded = false;
        $response->method('withAddedHeader')->willReturnCallback(
            function (string $name, string $value) use ($response, &$varyAdded) {
                if ($name === 'Vary' && $value === 'Turbo-Frame') {
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
}
