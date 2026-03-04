<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\RequestNormalizationMiddleware;

#[CoversClass(RequestNormalizationMiddleware::class)]
final class RequestNormalizationMiddlewareTest extends TestCase
{
    private RequestNormalizationMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new RequestNormalizationMiddleware();
    }

    #[Test]
    public function allowsNormalPath(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/api/users/123');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $this->middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    #[DataProvider('traversalPaths')]
    public function rejectsPathTraversalAttempts(string $path): void
    {
        $request = new ServerRequest(method: 'GET', uri: $path);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $this->middleware->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversalPaths(): iterable
    {
        yield 'dot-dot-slash' => ['/etc/../passwd'];
        yield 'dot-dot-backslash' => ['/etc/..\\passwd'];
        yield 'trailing dot-dot' => ['/secret/..'];
        yield 'encoded dot-dot' => ['/%2e%2e/etc/passwd'];
        yield 'mixed encoding' => ['/..%2f..%2fetc/passwd'];
        yield 'deep traversal' => ['/a/b/c/../../../etc/passwd'];
    }

    #[Test]
    public function rejectsNullByteInPath(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/file%00.php');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $this->middleware->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function allowsDotInNormalPath(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/api/file.json');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $this->middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function allowsRootPath(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $this->middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }
}
