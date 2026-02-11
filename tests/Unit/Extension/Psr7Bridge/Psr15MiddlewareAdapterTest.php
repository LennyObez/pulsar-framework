<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Psr7Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as Psr15MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Psr7Bridge\Middleware\Psr15MiddlewareAdapter;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

use function assert;

#[CoversClass(Psr15MiddlewareAdapter::class)]
final class Psr15MiddlewareAdapterTest extends TestCase
{
    #[Test]
    public function psr15MiddlewareCanPassThroughToNextHandler(): void
    {
        // Create a PSR-15 middleware that simply passes through
        $psr15Middleware = new class implements Psr15MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $adapter = new Psr15MiddlewareAdapter($psr15Middleware);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        $expectedResponse = new Response(
            body: 'Hello from Pulsar',
            status: ResponseStatus::OK,
        );

        $next = static fn(Request $req): Response => $expectedResponse;

        $result = $adapter->process($request, $next);

        self::assertSame('Hello from Pulsar', $result->body);
        self::assertSame(ResponseStatus::OK, $result->status);
    }

    #[Test]
    public function psr15MiddlewareCanModifyRequest(): void
    {
        // Create a PSR-15 middleware that adds a header to the request
        $psr15Middleware = new class implements Psr15MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $request = $request->withAttribute('middleware_applied', true);

                return $handler->handle($request);
            }
        };

        $adapter = new Psr15MiddlewareAdapter($psr15Middleware);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        $capturedAttribute = null;
        $next = static function (Request $req) use (&$capturedAttribute): Response {
            $capturedAttribute = $req->attribute('middleware_applied');

            return new Response(body: 'OK', status: ResponseStatus::OK);
        };

        $adapter->process($request, $next);

        self::assertTrue($capturedAttribute);
    }

    #[Test]
    public function psr15MiddlewareCanShortCircuitResponse(): void
    {
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();

        // Create a PSR-15 middleware that returns early without calling the handler
        $psr15Middleware = new class ($factory) implements Psr15MiddlewareInterface {
            public function __construct(
                private readonly \Nyholm\Psr7\Factory\Psr17Factory $factory,
            ) {}

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                // Short-circuit: return 403 without calling the handler
                $response = $this->factory->createResponse(403, 'Forbidden');

                return $response->withBody($this->factory->createStream('Access denied'));
            }
        };

        $adapter = new Psr15MiddlewareAdapter($psr15Middleware);

        $request = new Request(
            method: Method::GET,
            uri: '/admin',
            path: '/admin',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        $nextCalled = false;
        $next = static function (Request $req) use (&$nextCalled): Response {
            $nextCalled = true;

            return new Response(body: 'Should not reach here', status: ResponseStatus::OK);
        };

        $result = $adapter->process($request, $next);

        self::assertFalse($nextCalled);
        self::assertSame(ResponseStatus::Forbidden, $result->status);
        self::assertSame('Access denied', $result->body);
    }

    #[Test]
    public function psr15MiddlewareCanModifyResponse(): void
    {
        // Create a PSR-15 middleware that adds a header to the response
        $psr15Middleware = new class implements Psr15MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $response = $handler->handle($request);

                return $response->withHeader('X-Processed-By', 'PSR-15');
            }
        };

        $adapter = new Psr15MiddlewareAdapter($psr15Middleware);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        $next = static fn(Request $req): Response => new Response(
            body: 'Original',
            status: ResponseStatus::OK,
        );

        $result = $adapter->process($request, $next);

        self::assertSame('Original', $result->body);
        self::assertSame('PSR-15', $result->headers->first('X-Processed-By'));
    }

    #[Test]
    public function psr15MiddlewarePreservesRequestData(): void
    {
        // Create a PSR-15 middleware that reads and passes through request data
        $psr15Middleware = new class implements Psr15MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                // Verify PSR-7 request has the expected data
                assert($request->getMethod() === 'POST');
                assert($request->getQueryParams() === ['page' => '1']);

                return $handler->handle($request);
            }
        };

        $adapter = new Psr15MiddlewareAdapter($psr15Middleware);

        $request = new Request(
            method: Method::POST,
            uri: '/api/data?page=1',
            path: '/api/data',
            queryString: 'page=1',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '{"key":"value"}',
            query: ['page' => '1'],
            post: ['key' => 'value'],
        );

        $capturedRequest = null;
        $next = static function (Request $req) use (&$capturedRequest): Response {
            $capturedRequest = $req;

            return new Response(body: 'Done', status: ResponseStatus::OK);
        };

        $adapter->process($request, $next);

        self::assertNotNull($capturedRequest);
        self::assertSame(Method::POST, $capturedRequest->method);
        self::assertSame(['page' => '1'], $capturedRequest->query);
    }
}
