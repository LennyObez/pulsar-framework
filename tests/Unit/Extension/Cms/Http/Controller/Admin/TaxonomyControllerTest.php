<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\TaxonomyController;
use Pulsar\Extension\Cms\Taxonomy\Taxonomy;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTerm;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(TaxonomyController::class)]
final class TaxonomyControllerTest extends TestCase
{
    #[Test]
    public function index_returns_taxonomy_listing(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body['taxonomies']);
        self::assertSame('en', $body['locale']);
    }

    #[Test]
    public function show_returns_taxonomy_with_terms(): void
    {
        $taxonomy = $this->createTaxonomy('tax-1', 'category');
        $term = $this->createTerm('term-1', 'tax-1');

        $repo = $this->createStub(TaxonomyRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($taxonomy);
        $repo->method('findTerms')->willReturn([$term]);

        $controller = $this->createController(repo: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'category');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $taxonomyData */
        $taxonomyData = $body['taxonomy'];
        self::assertSame('tax-1', $taxonomyData['id']);
        self::assertSame('category', $taxonomyData['slug']);
        self::assertTrue($taxonomyData['hierarchical']);

        /** @var list<array<string, mixed>> $terms */
        $terms = $body['terms'];
        self::assertCount(1, $terms);
        self::assertSame('term-1', $terms[0]['id']);
    }

    #[Test]
    public function show_returns_404_when_not_found(): void
    {
        $repo = $this->createStub(TaxonomyRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn(null);

        $controller = $this->createController(repo: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function create_returns_201(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest();

        $response = $controller->create($request);

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function update_returns_success(): void
    {
        $taxonomy = $this->createTaxonomy('tax-1', 'category');

        $repo = $this->createStub(TaxonomyRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($taxonomy);

        $controller = $this->createController(repo: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->update($request, 'category');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('updated', $body['status']);
    }

    #[Test]
    public function update_returns_404_when_not_found(): void
    {
        $repo = $this->createStub(TaxonomyRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn(null);

        $controller = $this->createController(repo: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->update($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_success(): void
    {
        $taxonomy = $this->createTaxonomy('tax-1', 'category');

        $repo = $this->createStub(TaxonomyRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($taxonomy);

        $controller = $this->createController(repo: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->delete($request, 'category');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function delete_returns_404_when_not_found(): void
    {
        $repo = $this->createStub(TaxonomyRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn(null);

        $controller = $this->createController(repo: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->delete($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $controller = $this->createController();

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->createController(gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    private function createController(
        ?TaxonomyRepositoryInterface $repo = null,
        ?GateInterface $gate = null,
    ): TaxonomyController {
        return new TaxonomyController(
            taxonomyRepository: $repo ?? $this->createStub(TaxonomyRepositoryInterface::class),
            gate: $gate,
        );
    }

    private function createTaxonomy(string $id, string $slug): Taxonomy
    {
        return new Taxonomy(
            id: $id,
            tenantId: null,
            slug: $slug,
            hierarchical: true,
            createdAt: new DateTimeImmutable('2026-03-10T12:00:00+00:00'),
        );
    }

    private function createTerm(string $id, string $taxonomyId): TaxonomyTerm
    {
        return new TaxonomyTerm(
            id: $id,
            taxonomyId: $taxonomyId,
            tenantId: null,
            parentId: null,
            sortOrder: 0,
            createdAt: new DateTimeImmutable('2026-03-10T12:00:00+00:00'),
        );
    }

    private function createAuthenticatedRequest(): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/taxonomies');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                default => $default,
            },
        );

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/taxonomies');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
