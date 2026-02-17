<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\SitemapController;
use Pulsar\Extension\Cms\Seo\SitemapGeneratorInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(SitemapController::class)]
final class SitemapControllerTest extends TestCase
{
    private const SITEMAP_INDEX_XML = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
          <sitemap>
            <loc>https://example.com/sitemap-pages.xml</loc>
            <lastmod>2026-03-01</lastmod>
          </sitemap>
          <sitemap>
            <loc>https://example.com/sitemap-posts.xml</loc>
          </sitemap>
        </sitemapindex>
        XML;

    private const EMPTY_SITEMAP_INDEX_XML = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
        </sitemapindex>
        XML;

    #[Test]
    public function preview_returns_segments_from_sitemap_index(): void
    {
        $generator = $this->createStub(SitemapGeneratorInterface::class);
        $generator->method('generateIndex')->willReturn(self::SITEMAP_INDEX_XML);

        $controller = new SitemapController(sitemapGenerator: $generator);
        $request = $this->createAuthenticatedRequest(queryParams: ['base_url' => 'https://example.com']);

        $response = $controller->preview($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array{loc: string, lastmod: string|null}> $segments */
        $segments = $body['segments'];
        self::assertCount(2, $segments);
        self::assertSame('https://example.com/sitemap-pages.xml', $segments[0]['loc']);
        self::assertSame('2026-03-01', $segments[0]['lastmod']);
        self::assertSame('https://example.com/sitemap-posts.xml', $segments[1]['loc']);
        self::assertNull($segments[1]['lastmod']);
        self::assertSame(2, $body['total_segments']);
        self::assertSame('https://example.com', $body['base_url']);
    }

    #[Test]
    public function preview_uses_base_url_from_request_attribute_when_query_param_empty(): void
    {
        $generator = $this->createMock(SitemapGeneratorInterface::class);
        $generator->expects(self::once())
            ->method('generateIndex')
            ->with('https://attr.example.com', null)
            ->willReturn(self::EMPTY_SITEMAP_INDEX_XML);

        $controller = new SitemapController(sitemapGenerator: $generator);
        $request = $this->createAuthenticatedRequest(baseUrlAttribute: 'https://attr.example.com');

        $controller->preview($request);
    }

    #[Test]
    public function preview_passes_tenant_id_from_request_attribute(): void
    {
        $generator = $this->createMock(SitemapGeneratorInterface::class);
        $generator->expects(self::once())
            ->method('generateIndex')
            ->with('https://example.com', 'tenant-42')
            ->willReturn(self::EMPTY_SITEMAP_INDEX_XML);

        $controller = new SitemapController(sitemapGenerator: $generator);
        $request = $this->createAuthenticatedRequest(
            queryParams: ['base_url' => 'https://example.com'],
            tenantId: 'tenant-42',
        );

        $controller->preview($request);
    }

    #[Test]
    public function preview_handles_empty_sitemap_index(): void
    {
        $generator = $this->createStub(SitemapGeneratorInterface::class);
        $generator->method('generateIndex')->willReturn(self::EMPTY_SITEMAP_INDEX_XML);

        $controller = new SitemapController(sitemapGenerator: $generator);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->preview($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['total_segments']);
        self::assertSame([], $body['segments']);
    }

    #[Test]
    public function preview_handles_invalid_xml_gracefully(): void
    {
        $generator = $this->createStub(SitemapGeneratorInterface::class);
        $generator->method('generateIndex')->willReturn('not valid xml <><>');

        $controller = new SitemapController(sitemapGenerator: $generator);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->preview($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['total_segments']);
        self::assertSame([], $body['segments']);
    }

    #[Test]
    public function preview_throws_when_unauthenticated(): void
    {
        $generator = $this->createStub(SitemapGeneratorInterface::class);
        $controller = new SitemapController(sitemapGenerator: $generator);

        $this->expectException(AuthenticationException::class);
        $controller->preview($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function preview_throws_when_authorization_denied(): void
    {
        $generator = $this->createStub(SitemapGeneratorInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new SitemapController(sitemapGenerator: $generator, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->preview($request);
    }

    #[Test]
    public function regenerate_returns_segment_count(): void
    {
        $generator = $this->createStub(SitemapGeneratorInterface::class);
        $generator->method('generateIndex')->willReturn(self::SITEMAP_INDEX_XML);

        $controller = new SitemapController(sitemapGenerator: $generator);
        $request = $this->createAuthenticatedRequest(queryParams: ['base_url' => 'https://example.com']);

        $response = $controller->regenerate($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['regenerated']);
        self::assertSame(2, $body['segments']);
    }

    #[Test]
    public function regenerate_throws_when_unauthenticated(): void
    {
        $generator = $this->createStub(SitemapGeneratorInterface::class);
        $controller = new SitemapController(sitemapGenerator: $generator);

        $this->expectException(AuthenticationException::class);
        $controller->regenerate($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function regenerate_throws_when_authorization_denied(): void
    {
        $generator = $this->createStub(SitemapGeneratorInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new SitemapController(sitemapGenerator: $generator, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->regenerate($request);
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function createAuthenticatedRequest(
        array $queryParams = [],
        string $baseUrlAttribute = '',
        ?string $tenantId = null,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/seo/sitemap');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'base_url' => $baseUrlAttribute !== '' ? $baseUrlAttribute : $default,
                'tenant_id' => $tenantId,
                default => $default,
            },
        );

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/seo/sitemap');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
