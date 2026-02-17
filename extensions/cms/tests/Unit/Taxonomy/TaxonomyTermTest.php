<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Taxonomy;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTerm;

#[CoversClass(TaxonomyTerm::class)]
final class TaxonomyTermTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-01T10:00:00Z');

        $term = new TaxonomyTerm(
            id: 'term-001',
            taxonomyId: 'tax-001',
            tenantId: 'tenant-01',
            parentId: 'term-parent',
            sortOrder: 5,
            createdAt: $now,
            importId: 'imp-term-001',
        );

        self::assertSame('term-001', $term->id);
        self::assertSame('tax-001', $term->taxonomyId);
        self::assertSame('tenant-01', $term->tenantId);
        self::assertSame('term-parent', $term->parentId);
        self::assertSame(5, $term->sortOrder);
        self::assertSame($now, $term->createdAt);
        self::assertSame('imp-term-001', $term->importId);
    }

    #[Test]
    public function rootTermHasNullParentId(): void
    {
        $term = new TaxonomyTerm(
            id: 'term-002',
            taxonomyId: 'tax-001',
            tenantId: null,
            parentId: null,
            sortOrder: 0,
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($term->parentId);
        self::assertNull($term->tenantId);
    }

    #[Test]
    public function importIdDefaultsToNull(): void
    {
        $term = new TaxonomyTerm(
            id: 'term-003',
            taxonomyId: 'tax-002',
            tenantId: null,
            parentId: null,
            sortOrder: 0,
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($term->importId);
    }
}
