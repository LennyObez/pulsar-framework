<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Taxonomy;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Taxonomy\Taxonomy;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTerm;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTermTranslation;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTranslation;

#[CoversClass(Taxonomy::class)]
#[CoversClass(TaxonomyTerm::class)]
#[CoversClass(TaxonomyTermTranslation::class)]
#[CoversClass(TaxonomyTranslation::class)]
final class TaxonomyEntitiesTest extends TestCase
{
    #[Test]
    public function taxonomyConstructor(): void
    {
        $now = new DateTimeImmutable('2025-03-07T10:00:00+00:00');

        $taxonomy = new Taxonomy(
            id: 'tax-01',
            tenantId: 'tenant-01',
            slug: 'category',
            hierarchical: true,
            createdAt: $now,
        );

        self::assertSame('tax-01', $taxonomy->id);
        self::assertSame('tenant-01', $taxonomy->tenantId);
        self::assertSame('category', $taxonomy->slug);
        self::assertTrue($taxonomy->hierarchical);
        self::assertSame($now, $taxonomy->createdAt);
    }

    #[Test]
    public function taxonomyWithoutTenant(): void
    {
        $taxonomy = new Taxonomy(
            id: 'tax-02',
            tenantId: null,
            slug: 'tag',
            hierarchical: false,
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($taxonomy->tenantId);
        self::assertFalse($taxonomy->hierarchical);
    }

    #[Test]
    public function taxonomyTermWithParent(): void
    {
        $now = new DateTimeImmutable();

        $term = new TaxonomyTerm(
            id: 'term-01',
            taxonomyId: 'tax-01',
            tenantId: 'tenant-01',
            parentId: 'term-root',
            sortOrder: 2,
            createdAt: $now,
        );

        self::assertSame('term-01', $term->id);
        self::assertSame('tax-01', $term->taxonomyId);
        self::assertSame('term-root', $term->parentId);
        self::assertSame(2, $term->sortOrder);
    }

    #[Test]
    public function taxonomyTermRootLevel(): void
    {
        $term = new TaxonomyTerm(
            id: 'term-02',
            taxonomyId: 'tax-01',
            tenantId: null,
            parentId: null,
            sortOrder: 0,
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($term->parentId);
        self::assertNull($term->tenantId);
    }

    #[Test]
    public function taxonomyTermTranslationConstructor(): void
    {
        $trans = new TaxonomyTermTranslation(
            termId: 'term-01',
            locale: 'de',
            name: 'Technologie',
            slug: 'technologie',
            description: 'Beitraege ueber Technologie',
        );

        self::assertSame('term-01', $trans->termId);
        self::assertSame('de', $trans->locale);
        self::assertSame('Technologie', $trans->name);
        self::assertSame('technologie', $trans->slug);
        self::assertSame('Beitraege ueber Technologie', $trans->description);
    }

    #[Test]
    public function taxonomyTermTranslationWithoutDescription(): void
    {
        $trans = new TaxonomyTermTranslation(
            termId: 'term-02',
            locale: 'en',
            name: 'Technology',
            slug: 'technology',
            description: null,
        );

        self::assertNull($trans->description);
    }

    #[Test]
    public function taxonomyTranslationConstructor(): void
    {
        $trans = new TaxonomyTranslation(
            taxonomyId: 'tax-01',
            locale: 'fr',
            name: 'Categories',
            description: 'Organisation du contenu par categories',
        );

        self::assertSame('tax-01', $trans->taxonomyId);
        self::assertSame('fr', $trans->locale);
        self::assertSame('Categories', $trans->name);
        self::assertSame('Organisation du contenu par categories', $trans->description);
    }

    #[Test]
    public function taxonomyTranslationWithoutDescription(): void
    {
        $trans = new TaxonomyTranslation(
            taxonomyId: 'tax-02',
            locale: 'en',
            name: 'Tags',
            description: null,
        );

        self::assertNull($trans->description);
    }
}
