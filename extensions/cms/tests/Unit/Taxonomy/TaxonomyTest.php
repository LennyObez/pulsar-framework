<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Taxonomy;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Taxonomy\Taxonomy;

#[CoversClass(Taxonomy::class)]
final class TaxonomyTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-01T10:00:00Z');

        $taxonomy = new Taxonomy(
            id: 'tax-001',
            tenantId: 'tenant-01',
            slug: 'category',
            hierarchical: true,
            createdAt: $now,
            importId: 'imp-001',
        );

        self::assertSame('tax-001', $taxonomy->id);
        self::assertSame('tenant-01', $taxonomy->tenantId);
        self::assertSame('category', $taxonomy->slug);
        self::assertTrue($taxonomy->hierarchical);
        self::assertSame($now, $taxonomy->createdAt);
        self::assertSame('imp-001', $taxonomy->importId);
    }

    #[Test]
    public function flatTaxonomyIsNotHierarchical(): void
    {
        $taxonomy = new Taxonomy(
            id: 'tax-002',
            tenantId: null,
            slug: 'tag',
            hierarchical: false,
            createdAt: new DateTimeImmutable(),
        );

        self::assertFalse($taxonomy->hierarchical);
        self::assertSame('tag', $taxonomy->slug);
    }

    #[Test]
    public function importIdDefaultsToNull(): void
    {
        $taxonomy = new Taxonomy(
            id: 'tax-003',
            tenantId: null,
            slug: 'topic',
            hierarchical: true,
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($taxonomy->importId);
    }
}
