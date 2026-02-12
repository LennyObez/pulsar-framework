<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Graphql\Resolver;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Taxonomy\Taxonomy;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTerm;
use Pulsar\Extension\Graphql\Resolver\TaxonomyResolver;

#[CoversClass(TaxonomyResolver::class)]
final class TaxonomyResolverTest extends TestCase
{
    private TaxonomyRepositoryInterface&Stub $taxonomyRepo;
    private TaxonomyResolver $resolver;

    protected function setUp(): void
    {
        $this->taxonomyRepo = $this->createStub(TaxonomyRepositoryInterface::class);
        $this->resolver = new TaxonomyResolver($this->taxonomyRepo);
    }

    #[Test]
    public function resolve_by_slug_returns_null_when_not_found(): void
    {
        $this->taxonomyRepo->method('findBySlug')->willReturn(null);

        self::assertNull($this->resolver->resolveBySlug('nonexistent'));
    }

    #[Test]
    public function resolve_by_slug_returns_taxonomy_data(): void
    {
        $now = new DateTimeImmutable('2025-03-10T12:00:00+00:00');
        $taxonomy = new Taxonomy(
            id: 'tax-001',
            tenantId: null,
            slug: 'categories',
            hierarchical: true,
            createdAt: $now,
        );

        $this->taxonomyRepo->method('findBySlug')->willReturn($taxonomy);

        $result = $this->resolver->resolveBySlug('categories');

        self::assertNotNull($result);
        self::assertSame('tax-001', $result['id']);
        self::assertSame('categories', $result['slug']);
        self::assertTrue($result['hierarchical']);
        self::assertNull($result['tenantId']);
    }

    #[Test]
    public function resolve_terms_returns_term_list(): void
    {
        $now = new DateTimeImmutable('2025-03-10T12:00:00+00:00');
        $terms = [
            new TaxonomyTerm(
                id: 'term-001',
                taxonomyId: 'tax-001',
                tenantId: null,
                parentId: null,
                sortOrder: 0,
                createdAt: $now,
            ),
            new TaxonomyTerm(
                id: 'term-002',
                taxonomyId: 'tax-001',
                tenantId: null,
                parentId: 'term-001',
                sortOrder: 1,
                createdAt: $now,
            ),
        ];

        $this->taxonomyRepo->method('findTerms')->willReturn($terms);

        $result = $this->resolver->resolveTerms('tax-001', 'en');

        self::assertCount(2, $result);
        self::assertSame('term-001', $result[0]['id']);
        self::assertNull($result[0]['parentId']);
        self::assertSame('term-002', $result[1]['id']);
        self::assertSame('term-001', $result[1]['parentId']);
    }
}
