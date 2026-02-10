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
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
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

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
        );

        $expectedResponse = new Response(
            body: 'Hello from Pulsar',
            statusCode: ResponseStatus::OK->value,
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $result = $adapter->process($request, $handler);

        self::assertSame('Hello from Pulsar', (string) $result->getBody());
        self::assertSame(ResponseStatus::OK->value, $result->getStatusCode());
    }

    #[Test]
    public function psr15MiddlewareCanModifyRequest(): void
    {
        // Create a PSR-15 middleware that adds an attribute to the request
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

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
        );

        $capturedAttribute = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedAttribute): ResponseInterface {
                $capturedAttribute = $req->getAttribute('middleware_applied');
                return new Response(body: 'OK', statusCode: ResponseStatus::OK->value);
            },
        );

        $adapter->process($request, $handler);

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

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin',
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $result = $adapter->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $result->getStatusCode());
        self::assertSame('Access denied', (string) $result->getBody());
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

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(
            body: 'Original',
            statusCode: ResponseStatus::OK->value,
        ));

        $result = $adapter->process($request, $handler);

        self::assertSame('Original', (string) $result->getBody());
        self::assertSame('PSR-15', $result->getHeaderLine('X-Processed-By'));
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

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/data?page=1',
            headers: ['Content-Type' => 'application/json'],
            body: '{"key":"value"}',
            queryParams: ['page' => '1'],
            parsedBody: ['key' => 'value'],
        );

        $capturedRequest = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $req;
                return new Response(body: 'Done', statusCode: ResponseStatus::OK->value);
            },
        );

        $adapter->process($request, $handler);

        self::assertNotNull($capturedRequest);
        self::assertSame('POST', $capturedRequest->getMethod());
        self::assertSame(['page' => '1'], $capturedRequest->getQueryParams());
    }
}
