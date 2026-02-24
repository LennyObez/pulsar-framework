<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\LegacyMiddlewareAdapter;

use function assert;

#[CoversClass(LegacyMiddlewareAdapter::class)]
final class LegacyMiddlewareAdapterTest extends TestCase
{
    #[Test]
    public function processCallsLegacyMiddleware(): void
    {
        $called = false;
        $legacy = static function (ServerRequestInterface $request, callable $next) use (&$called): ResponseInterface {
            $called = true;
            $response = $next($request);
            assert($response instanceof ResponseInterface);

            return $response;
        };

        $adapter = new LegacyMiddlewareAdapter($legacy);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $adapter->process(new ServerRequest(), $handler);

        self::assertTrue($called);
    }

    #[Test]
    public function processLogsDeprecationWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('deprecated'));

        $legacy = static function (ServerRequestInterface $request, callable $next): ResponseInterface {
            $response = $next($request);
            assert($response instanceof ResponseInterface);

            return $response;
        };

        $adapter = new LegacyMiddlewareAdapter($legacy, $logger);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $adapter->process(new ServerRequest(), $handler);
    }

    #[Test]
    public function processEmitsDeprecationOnlyOnce(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $legacy = static function (ServerRequestInterface $request, callable $next): ResponseInterface {
            $response = $next($request);
            assert($response instanceof ResponseInterface);

            return $response;
        };

        $adapter = new LegacyMiddlewareAdapter($legacy, $logger);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $adapter->process(new ServerRequest(), $handler);
        $adapter->process(new ServerRequest(), $handler);
    }

    #[Test]
    public function processTriggersPhpDeprecationInDevMode(): void
    {
        $legacy = static function (ServerRequestInterface $request, callable $next): ResponseInterface {
            $response = $next($request);
            assert($response instanceof ResponseInterface);

            return $response;
        };

        $adapter = new LegacyMiddlewareAdapter($legacy, devMode: true);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $deprecationTriggered = false;
        set_error_handler(static function (int $errno) use (&$deprecationTriggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecationTriggered = true;
            }
            return true;
        });

        try {
            $adapter->process(new ServerRequest(), $handler);
        } finally {
            restore_error_handler();
        }

        self::assertTrue($deprecationTriggered);
    }

    #[Test]
    public function processPassesResponseFromLegacyMiddleware(): void
    {
        $expectedResponse = new Response(statusCode: 201);

        $legacy = static fn(ServerRequestInterface $request, callable $next): ResponseInterface => $expectedResponse;

        $adapter = new LegacyMiddlewareAdapter($legacy);
        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $adapter->process(new ServerRequest(), $handler);

        self::assertSame(201, $result->getStatusCode());
    }

    #[Test]
    public function processWithoutLoggerDoesNotThrow(): void
    {
        $legacy = static function (ServerRequestInterface $request, callable $next): ResponseInterface {
            $response = $next($request);
            assert($response instanceof ResponseInterface);

            return $response;
        };

        $adapter = new LegacyMiddlewareAdapter($legacy);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $result = $adapter->process(new ServerRequest(), $handler);

        self::assertSame(200, $result->getStatusCode());
    }
}
