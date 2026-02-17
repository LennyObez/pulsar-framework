<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\Internal\Middleware\CollectionCorsMiddleware;
use Pulsar\Http\Message\Response;

final class CollectionCorsMiddlewareTest extends TestCase
{
    private static function makeSite(string $domain = 'example.com'): Site
    {
        return new Site(
            id: 'site_1',
            domain: $domain,
            name: 'Test',
            trackingId: 'plsr_test1',
        );
    }

    #[Test]
    public function no_origin_header_passes_through_without_cors(): void
    {
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $middleware = new CollectionCorsMiddleware($repo);

        $request = $this->createRequestStub('POST', '');
        $expectedResponse = Response::json(['ok' => true]);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function known_origin_gets_cors_headers(): void
    {
        $site = self::makeSite();
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $repo->method('findByDomain')->willReturnCallback(
            static fn(string $domain) => $domain === 'example.com' ? $site : null,
        );

        $middleware = new CollectionCorsMiddleware($repo);

        $request = $this->createRequestStub('POST', 'https://example.com');
        $expectedResponse = Response::json(['ok' => true]);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertTrue($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[Test]
    public function unknown_origin_no_cors_headers(): void
    {
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $repo->method('findByDomain')->willReturn(null);

        $middleware = new CollectionCorsMiddleware($repo);

        $request = $this->createRequestStub('POST', 'https://unknown.com');
        $expectedResponse = Response::json(['ok' => true]);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function preflight_with_known_origin_returns_204_with_cors(): void
    {
        $site = self::makeSite();
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $repo->method('findByDomain')->willReturnCallback(
            static fn(string $domain) => $domain === 'example.com' ? $site : null,
        );

        $middleware = new CollectionCorsMiddleware($repo);
        $request = $this->createRequestStub('OPTIONS', 'https://example.com');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
        self::assertTrue($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertTrue($response->hasHeader('Access-Control-Max-Age'));
    }

    #[Test]
    public function preflight_with_unknown_origin_returns_204_without_cors(): void
    {
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $repo->method('findByDomain')->willReturn(null);

        $middleware = new CollectionCorsMiddleware($repo);
        $request = $this->createRequestStub('OPTIONS', 'https://malicious.com');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function www_prefix_stripped_for_lookup(): void
    {
        $site = self::makeSite();
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $repo->method('findByDomain')->willReturnCallback(
            static fn(string $domain) => $domain === 'example.com' ? $site : null,
        );

        $middleware = new CollectionCorsMiddleware($repo);

        $request = $this->createRequestStub('POST', 'https://www.example.com');
        $expectedResponse = Response::json(['ok' => true]);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertTrue($response->hasHeader('Access-Control-Allow-Origin'));
    }

    private function createRequestStub(string $method, string $origin): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => match (strtolower($name)) {
                'origin' => $origin,
                default => '',
            },
        );

        return $request;
    }
}
