<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\BodySizeLimitMiddleware;

#[CoversClass(BodySizeLimitMiddleware::class)]
final class BodySizeLimitMiddlewareTest extends TestCase
{
    #[Test]
    public function allowsRequestWithinLimit(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxSizeMb: 8);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/upload',
            headers: ['Content-Length' => '1024'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function rejectsRequestExceedingLimit(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxSizeMb: 1);
        $twoMbInBytes = (string) (2 * 1_048_576);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/upload',
            headers: ['Content-Length' => $twoMbInBytes],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(413, $response->getStatusCode());
    }

    #[Test]
    public function allowsRequestAtExactLimit(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxSizeMb: 1);
        $exactOneMb = (string) 1_048_576;

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/data',
            headers: ['Content-Length' => $exactOneMb],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function allowsRequestWithNoContentLength(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxSizeMb: 8);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/data',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function rejectsLargePostBody(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxSizeMb: 8);
        $fiftyMb = (string) (50 * 1_048_576);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/import',
            headers: ['Content-Length' => $fiftyMb],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(413, $response->getStatusCode());
    }
}
