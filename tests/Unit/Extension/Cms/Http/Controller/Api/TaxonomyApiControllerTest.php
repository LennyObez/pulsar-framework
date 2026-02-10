<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Http\Controller\Api\TaxonomyApiController;
use Pulsar\Extension\Cms\Taxonomy\Taxonomy;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTerm;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

#[CoversClass(TaxonomyApiController::class)]
final class TaxonomyApiControllerTest extends TestCase
{
    private TaxonomyRepositoryInterface&Stub $repository;
    private CmsConfig $config;
    private TaxonomyApiController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(TaxonomyRepositoryInterface::class);
        $this->config = new CmsConfig();
        $this->controller = new TaxonomyApiController($this->repository, $this->config);
    }

    #[Test]
    public function show_returns_taxonomy_by_slug(): void
    {
        $taxonomy = new Taxonomy(
            id: 'tax-1',
            tenantId: null,
            slug: 'categories',
            hierarchical: true,
            createdAt: new DateTimeImmutable(),
        );

        $this->repository->method('findBySlug')->willReturn($taxonomy);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/taxonomies/categories');

        $response = $this->controller->show($request, 'categories');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{data: array{id: string, slug: string, hierarchical: bool}} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('tax-1', $body['data']['id']);
        self::assertSame('categories', $body['data']['slug']);
        self::assertTrue($body['data']['hierarchical']);
    }

    #[Test]
    public function show_returns_404_for_missing_taxonomy(): void
    {
        $this->repository->method('findBySlug')->willReturn(null);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/taxonomies/nonexistent');

        $response = $this->controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());

        /** @var array{error: string, status: int} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Taxonomy not found', $body['error']);
    }

    #[Test]
    public function terms_returns_terms_for_taxonomy(): void
    {
        $taxonomy = new Taxonomy(
            id: 'tax-1',
            tenantId: null,
            slug: 'categories',
            hierarchical: true,
            createdAt: new DateTimeImmutable(),
        );

        $term = new TaxonomyTerm(
            id: 'term-1',
            taxonomyId: 'tax-1',
            tenantId: null,
            parentId: null,
            sortOrder: 0,
            createdAt: new DateTimeImmutable(),
        );

        $this->repository->method('findBySlug')->willReturn($taxonomy);
        $this->repository->method('findTerms')->willReturn([$term]);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/taxonomies/categories/terms');

        $response = $this->controller->terms($request, 'categories');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{data: list<array{id: string}>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('term-1', $body['data'][0]['id']);
    }

    #[Test]
    public function terms_returns_404_for_missing_taxonomy(): void
    {
        $this->repository->method('findBySlug')->willReturn(null);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/taxonomies/nonexistent/terms');

        $response = $this->controller->terms($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }
}
