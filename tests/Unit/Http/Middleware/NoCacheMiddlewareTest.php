<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Attribute\NoCacheResponse;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\NoCacheMiddleware;

#[CoversClass(NoCacheMiddleware::class)]
final class NoCacheMiddlewareTest extends TestCase
{
    private function handler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        return $handler;
    }

    #[Test]
    public function noHeadersWithoutControllerAttribute(): void
    {
        $middleware = new NoCacheMiddleware();
        $request = new ServerRequest(method: 'GET', uri: '/');

        $response = $middleware->process($request, $this->handler());

        self::assertSame('', $response->getHeaderLine('Cache-Control'));
        self::assertSame('', $response->getHeaderLine('Pragma'));
        self::assertSame('', $response->getHeaderLine('Expires'));
    }

    #[Test]
    public function appliesCacheHeadersWhenMethodHasAttribute(): void
    {
        $middleware = new NoCacheMiddleware();
        $request = new ServerRequest(method: 'GET', uri: '/sensitive')
            ->withAttribute('_controller', [NoCacheTestController::class, 'sensitive']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('no-store, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        self::assertSame('0', $response->getHeaderLine('Expires'));
    }

    #[Test]
    public function noHeadersForMethodWithoutAttribute(): void
    {
        $middleware = new NoCacheMiddleware();
        $request = new ServerRequest(method: 'GET', uri: '/public')
            ->withAttribute('_controller', [NoCacheTestController::class, 'publicPage']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function appliesForClassLevelAttribute(): void
    {
        $middleware = new NoCacheMiddleware();
        $request = new ServerRequest(method: 'GET', uri: '/')
            ->withAttribute('_controller', [NoCacheClassController::class, 'index']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('no-store, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function supportsStringControllerFormat(): void
    {
        $middleware = new NoCacheMiddleware();
        $request = new ServerRequest(method: 'GET', uri: '/')
            ->withAttribute('_controller', NoCacheTestController::class . '::sensitive');

        $response = $middleware->process($request, $this->handler());

        self::assertSame('no-store, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function handlesNonExistentClassGracefully(): void
    {
        $middleware = new NoCacheMiddleware();
        $request = new ServerRequest(method: 'GET', uri: '/')
            ->withAttribute('_controller', ['NonExistentClass', 'method']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('', $response->getHeaderLine('Cache-Control'));
    }
}

// Test fixtures
class NoCacheTestController
{
    #[NoCacheResponse]
    public function sensitive(): void {}

    public function publicPage(): void {}
}

#[NoCacheResponse]
class NoCacheClassController
{
    public function index(): void {}
}
