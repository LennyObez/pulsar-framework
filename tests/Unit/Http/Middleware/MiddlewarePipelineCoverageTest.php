<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use RuntimeException;

#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineCoverageTest extends TestCase
{
    private function createRequest(): ServerRequest
    {
        return new ServerRequest(method: 'GET', uri: '/');
    }

    #[Test]
    public function processPassesThroughMiddlewareWithExternalHandler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withAttribute('piped', true));
            }
        };

        $pipeline->pipe($middleware);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('processed'));

        $response = $pipeline->process($this->createRequest(), $handler);

        self::assertSame('processed', (string) $response->getBody());
    }

    #[Test]
    public function handleThrowsWhenNoFallbackHandler(): void
    {
        $pipeline = new MiddlewarePipeline();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No fallback handler set');

        $pipeline->handle($this->createRequest());
    }

    #[Test]
    public function setHandlerAllowsHandleToBeCalled(): void
    {
        $pipeline = new MiddlewarePipeline();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('via-setHandler'));

        $pipeline->setHandler($handler);
        $response = $pipeline->handle($this->createRequest());

        self::assertSame('via-setHandler', (string) $response->getBody());
    }

    #[Test]
    public function processFollowedByHandleReusesResolvedMiddleware(): void
    {
        $callCount = 0;
        $pipeline = new MiddlewarePipeline();
        $middleware = new class ($callCount) implements MiddlewareInterface {
            public function __construct(private int &$count) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->count++;
                return $handler->handle($request);
            }
        };

        $pipeline->pipe($middleware);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('ok'));

        // First call via process()
        $pipeline->process($this->createRequest(), $handler);
        self::assertSame(1, $callCount);

        // Second call via handle() reuses the cached resolved middleware
        $pipeline->handle($this->createRequest());
        self::assertSame(2, $callCount);
    }

    #[Test]
    public function pipeInvalidatesResolvedMiddlewareCache(): void
    {
        $pipeline = new MiddlewarePipeline();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('ok'));

        // First dispatch resolves and caches middleware
        $pipeline->dispatch($this->createRequest(), fn() => Response::text('ok'));

        // Adding new middleware should invalidate the cache
        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withHeader('X-Added', '1');
            }
        };
        $pipeline->pipe($middleware);

        $response = $pipeline->dispatch($this->createRequest(), fn() => Response::text('ok'));

        self::assertSame('1', $response->getHeaderLine('X-Added'));
    }

    #[Test]
    public function containerResolvesMiddlewareWhenAvailable(): void
    {
        $container = new \Pulsar\Container\Container();

        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withHeader('X-Container', 'resolved');
            }
        };

        $container->instance('my.middleware', $middleware);

        $pipeline = new MiddlewarePipeline($container);
        /** @var class-string<MiddlewareInterface> $key */
        $key = trim('my.middleware');
        $pipeline->pipe($key);

        $response = $pipeline->dispatch($this->createRequest(), fn() => Response::text('ok'));

        self::assertSame('resolved', $response->getHeaderLine('X-Container'));
    }
}
