<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Taxonomy;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Taxonomy\Taxonomy;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTerm;

#[CoversClass(Taxonomy::class)]
#[CoversClass(TaxonomyTerm::class)]
final class TaxonomyImportIdTest extends TestCase
{
    #[Test]
    public function taxonomyAcceptsImportId(): void
    {
        $taxonomy = new Taxonomy(
            id: 'tax-1',
            tenantId: null,
            slug: 'categories',
            hierarchical: true,
            createdAt: new DateTimeImmutable(),
            importId: 'tax:categories',
        );

        self::assertSame('tax:categories', $taxonomy->importId);
    }

    #[Test]
    public function taxonomyImportIdDefaultsToNull(): void
    {
        $taxonomy = new Taxonomy(
            id: 'tax-2',
            tenantId: null,
            slug: 'tags',
            hierarchical: false,
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($taxonomy->importId);
    }

    #[Test]
    public function taxonomyTermAcceptsImportId(): void
    {
        $term = new TaxonomyTerm(
            id: 'term-1',
            taxonomyId: 'tax-1',
            tenantId: null,
            parentId: null,
            sortOrder: 0,
            createdAt: new DateTimeImmutable(),
            importId: 'term:php',
        );

        self::assertSame('term:php', $term->importId);
    }

    #[Test]
    public function taxonomyTermImportIdDefaultsToNull(): void
    {
        $term = new TaxonomyTerm(
            id: 'term-2',
            taxonomyId: 'tax-1',
            tenantId: null,
            parentId: null,
            sortOrder: 1,
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($term->importId);
    }

    #[Test]
    public function taxonomyPreservesAllFieldsWithImportId(): void
    {
        $now = new DateTimeImmutable();
        $taxonomy = new Taxonomy(
            id: 'tax-3',
            tenantId: 'tenant-1',
            slug: 'custom-tax',
            hierarchical: true,
            createdAt: $now,
            importId: 'tax:custom',
        );

        self::assertSame('tax-3', $taxonomy->id);
        self::assertSame('tenant-1', $taxonomy->tenantId);
        self::assertSame('custom-tax', $taxonomy->slug);
        self::assertTrue($taxonomy->hierarchical);
        self::assertSame($now, $taxonomy->createdAt);
        self::assertSame('tax:custom', $taxonomy->importId);
    }
}
