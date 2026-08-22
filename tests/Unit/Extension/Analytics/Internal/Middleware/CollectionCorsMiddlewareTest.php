<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\Internal\Middleware\CollectionCorsMiddleware;
use Pulsar\Http\Message\Response;

#[CoversClass(CollectionCorsMiddleware::class)]
final class CollectionCorsMiddlewareTest extends TestCase
{
    private SiteRepositoryInterface&Stub $siteRepository;
    private RequestHandlerInterface&Stub $handler;
    private ResponseInterface $normalResponse;

    protected function setUp(): void
    {
        $this->siteRepository = $this->createStub(SiteRepositoryInterface::class);
        $this->handler = $this->createStub(RequestHandlerInterface::class);
        $this->normalResponse = Response::json(['ok' => true]);
        $this->handler->method('handle')->willReturn($this->normalResponse);
    }

    #[Test]
    public function passesThroughWithoutOriginHeader(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getMethod')->willReturn('POST');

        $middleware = new CollectionCorsMiddleware($this->siteRepository);
        $response = $middleware->process($request, $this->handler);

        self::assertSame($this->normalResponse, $response);
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function addsCorsHeadersForRegisteredSite(): void
    {
        $site = new Site(id: 's-1', domain: 'example.com', name: 'Test', trackingId: 'plsr_abc');
        $this->siteRepository->method('findByDomain')->willReturn($site);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('https://example.com');
        $request->method('getMethod')->willReturn('POST');

        $middleware = new CollectionCorsMiddleware($this->siteRepository);
        $response = $middleware->process($request, $this->handler);

        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    #[Test]
    public function noCorsHeadersForUnregisteredSite(): void
    {
        $this->siteRepository->method('findByDomain')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('https://evil.com');
        $request->method('getMethod')->willReturn('POST');

        $middleware = new CollectionCorsMiddleware($this->siteRepository);
        $response = $middleware->process($request, $this->handler);

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function preflightReturnsMaxAgeForRegisteredSite(): void
    {
        $site = new Site(id: 's-1', domain: 'example.com', name: 'Test', trackingId: 'plsr_abc');
        $this->siteRepository->method('findByDomain')->willReturn($site);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('https://example.com');
        $request->method('getMethod')->willReturn('OPTIONS');

        $middleware = new CollectionCorsMiddleware($this->siteRepository);
        $response = $middleware->process($request, $this->handler);

        self::assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function preflightReturns204ForUnregisteredSite(): void
    {
        $this->siteRepository->method('findByDomain')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('https://evil.com');
        $request->method('getMethod')->willReturn('OPTIONS');

        $middleware = new CollectionCorsMiddleware($this->siteRepository);
        $response = $middleware->process($request, $this->handler);

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function resolvesWwwPrefixedDomain(): void
    {
        $site = new Site(id: 's-1', domain: 'example.com', name: 'Test', trackingId: 'plsr_abc');
        $this->siteRepository->method('findByDomain')
            ->willReturnCallback(static fn(string $d) => $d === 'example.com' ? $site : null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('https://www.example.com');
        $request->method('getMethod')->willReturn('POST');

        $middleware = new CollectionCorsMiddleware($this->siteRepository);
        $response = $middleware->process($request, $this->handler);

        self::assertSame('https://www.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function handlesInvalidOriginGracefully(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('not-a-url');
        $request->method('getMethod')->willReturn('POST');

        $middleware = new CollectionCorsMiddleware($this->siteRepository);
        $response = $middleware->process($request, $this->handler);

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }
}
