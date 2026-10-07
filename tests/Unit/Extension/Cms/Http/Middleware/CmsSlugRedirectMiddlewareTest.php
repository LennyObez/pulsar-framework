<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Middleware;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Http\Middleware\CmsSlugRedirectMiddleware;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(CmsSlugRedirectMiddleware::class)]
final class CmsSlugRedirectMiddlewareTest extends TestCase
{
    #[Test]
    public function processPassesThroughForRootPath(): void
    {
        $repo = $this->createStub(RedirectRepositoryInterface::class);
        $middleware = new CmsSlugRedirectMiddleware($repo);

        $request = new ServerRequest(method: 'GET', uri: '/');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function processPassesThroughWhenNoRedirectFound(): void
    {
        $repo = $this->createStub(RedirectRepositoryInterface::class);
        $repo->method('findByPath')->willReturn(null);

        $middleware = new CmsSlugRedirectMiddleware($repo);
        $request = new ServerRequest(method: 'GET', uri: '/some-page');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function processReturns301RedirectWhenMatchFound(): void
    {
        $redirect = $this->buildRedirect('old-page', '/new-page', 301);

        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->method('findByPath')->willReturn($redirect);
        $repo->expects(self::once())->method('incrementHits')->with($redirect->id);

        $middleware = new CmsSlugRedirectMiddleware($repo);
        $request = new ServerRequest(method: 'GET', uri: 'http://example.com/old-page');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        self::assertSame(301, $result->getStatusCode());
        self::assertStringContainsString('/new-page', $result->getHeaderLine('Location'));
    }

    #[Test]
    public function processReturns308RedirectForMethodPreserving(): void
    {
        $redirect = $this->buildRedirect('old-page', '/new-page', 308);

        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->method('findByPath')->willReturn($redirect);
        $repo->expects(self::once())->method('incrementHits');

        $middleware = new CmsSlugRedirectMiddleware($repo);
        $request = new ServerRequest(method: 'POST', uri: 'http://example.com/old-page');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        self::assertSame(308, $result->getStatusCode());
    }

    #[Test]
    public function processNormalizesInvalidStatusCodeTo301(): void
    {
        $redirect = $this->buildRedirect('old-page', '/new-page', 302);

        $repo = $this->createStub(RedirectRepositoryInterface::class);
        $repo->method('findByPath')->willReturn($redirect);

        $middleware = new CmsSlugRedirectMiddleware($repo);
        $request = new ServerRequest(method: 'GET', uri: 'http://example.com/old-page');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        self::assertSame(301, $result->getStatusCode());
    }

    #[Test]
    public function processBlocksExternalRedirect(): void
    {
        $redirect = $this->buildRedirect('old-page', 'https://evil.com/phish', 301);

        $repo = $this->createStub(RedirectRepositoryInterface::class);
        $repo->method('findByPath')->willReturn($redirect);

        $middleware = new CmsSlugRedirectMiddleware($repo);
        $request = new ServerRequest(method: 'GET', uri: 'http://example.com/old-page');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        // External redirect is blocked — falls through to handler
        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function processAllowsSameHostAbsoluteRedirect(): void
    {
        $redirect = $this->buildRedirect('old-page', 'http://example.com/new-page', 301);

        $repo = $this->createStub(RedirectRepositoryInterface::class);
        $repo->method('findByPath')->willReturn($redirect);

        $middleware = new CmsSlugRedirectMiddleware($repo);
        $request = new ServerRequest(method: 'GET', uri: 'http://example.com/old-page');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        self::assertSame(301, $result->getStatusCode());
        self::assertSame('http://example.com/new-page', $result->getHeaderLine('Location'));
    }

    #[Test]
    public function processFallsBackToGlobalRedirect(): void
    {
        $redirect = $this->buildRedirect('fr/about', '/new-about', 301);

        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->method('findByPath')->willReturnCallback(
            fn(string $path, ?string $locale = null, ?string $tenantId = null): ?Redirect => match (true) {
                $locale === null && $path === 'fr/about' => $redirect,
                default => null,
            },
        );
        $repo->expects(self::once())->method('incrementHits');

        $middleware = new CmsSlugRedirectMiddleware($repo);
        $request = new ServerRequest(method: 'GET', uri: 'http://example.com/fr/about');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        self::assertSame(301, $result->getStatusCode());
    }

    private function buildRedirect(string $from, string $to, int $status = 301): Redirect
    {
        return new Redirect(
            id: 'redirect-' . md5($from),
            tenantId: null,
            fromPath: $from,
            toPath: $to,
            statusCode: $status,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: new DateTimeImmutable(),
            createdBy: 'user-1',
            reason: 'Test redirect',
        );
    }
}
