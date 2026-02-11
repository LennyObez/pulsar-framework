<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Taxonomy\Taxonomy;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTerm;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTermTranslation;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTranslation;

use function in_array;

#[CoversClass(Taxonomy::class)]
#[CoversClass(TaxonomyTerm::class)]
final class TaxonomyTest extends TestCase
{
    private InMemoryTaxonomyRepository $taxonomyRepo;
    private InMemoryTaxonomyService $taxonomyService;

    protected function setUp(): void
    {
        $this->taxonomyRepo = new InMemoryTaxonomyRepository();
        $this->taxonomyService = new InMemoryTaxonomyService();
    }

    #[Test]
    public function createTaxonomyWithTerms(): void
    {
        $taxonomy = new Taxonomy(
            id: 'tax-001',
            tenantId: null,
            slug: 'category',
            hierarchical: true,
            createdAt: new DateTimeImmutable(),
        );

        $translations = [
            new TaxonomyTranslation(
                taxonomyId: 'tax-001',
                locale: 'en',
                name: 'Category',
                description: 'Content categories',
            ),
        ];

        $this->taxonomyRepo->save($taxonomy, $translations);

        $found = $this->taxonomyRepo->findBySlug('category');
        self::assertNotNull($found);
        self::assertSame('tax-001', $found->id);
        self::assertSame('category', $found->slug);
        self::assertTrue($found->hierarchical);

        // Add terms
        $term = new TaxonomyTerm(
            id: 'term-001',
            taxonomyId: 'tax-001',
            tenantId: null,
            parentId: null,
            sortOrder: 0,
            createdAt: new DateTimeImmutable(),
        );

        $termTranslations = [
            new TaxonomyTermTranslation(
                termId: 'term-001',
                locale: 'en',
                name: 'Technology',
                slug: 'technology',
                description: 'Tech articles',
            ),
        ];

        $this->taxonomyRepo->saveTerm($term, $termTranslations);

        $terms = $this->taxonomyRepo->findTerms('tax-001', 'en');
        self::assertCount(1, $terms);
        self::assertSame('term-001', $terms[0]->id);
    }

    #[Test]
    public function attachTermsToContent(): void
    {
        $this->taxonomyService->attachTerms('content-001', ['term-001', 'term-002']);

        $attachedTerms = $this->taxonomyService->getTermsForContent('content-001');
        self::assertCount(2, $attachedTerms);
        self::assertContains('term-001', $attachedTerms);
        self::assertContains('term-002', $attachedTerms);
    }

    #[Test]
    public function detachTermsFromContent(): void
    {
        $this->taxonomyService->attachTerms('content-001', ['term-001', 'term-002', 'term-003']);
        $this->taxonomyService->detachTerms('content-001', ['term-002']);

        $attachedTerms = $this->taxonomyService->getTermsForContent('content-001');
        self::assertCount(2, $attachedTerms);
        self::assertContains('term-001', $attachedTerms);
        self::assertNotContains('term-002', $attachedTerms);
        self::assertContains('term-003', $attachedTerms);
    }

    #[Test]
    public function hierarchicalTaxonomyParentChild(): void
    {
        $taxonomy = new Taxonomy(
            id: 'tax-002',
            tenantId: null,
            slug: 'regions',
            hierarchical: true,
            createdAt: new DateTimeImmutable(),
        );
        $this->taxonomyRepo->save($taxonomy, [
            new TaxonomyTranslation('tax-002', 'en', 'Regions', null),
        ]);

        // Parent term
        $parent = new TaxonomyTerm(
            id: 'term-parent',
            taxonomyId: 'tax-002',
            tenantId: null,
            parentId: null,
            sortOrder: 0,
            createdAt: new DateTimeImmutable(),
        );
        $this->taxonomyRepo->saveTerm($parent, [
            new TaxonomyTermTranslation('term-parent', 'en', 'Europe', 'europe', null),
        ]);

        // Child term
        $child = new TaxonomyTerm(
            id: 'term-child',
            taxonomyId: 'tax-002',
            tenantId: null,
            parentId: 'term-parent',
            sortOrder: 0,
            createdAt: new DateTimeImmutable(),
        );
        $this->taxonomyRepo->saveTerm($child, [
            new TaxonomyTermTranslation('term-child', 'en', 'France', 'france', null),
        ]);

        // Root terms only
        $rootTerms = $this->taxonomyRepo->findTerms('tax-002', 'en', parentId: null);
        self::assertCount(1, $rootTerms);
        self::assertSame('term-parent', $rootTerms[0]->id);

        // Children of parent
        $childTerms = $this->taxonomyRepo->findTerms('tax-002', 'en', parentId: 'term-parent');
        self::assertCount(1, $childTerms);
        self::assertSame('term-child', $childTerms[0]->id);
        self::assertSame('term-parent', $childTerms[0]->parentId);
    }
}

final class InMemoryTaxonomyRepository implements TaxonomyRepositoryInterface
{
    /** @var array<string, Taxonomy> */
    private array $taxonomies = [];

    /** @var array<string, TaxonomyTerm> */
    private array $terms = [];

    /** @var array<string, list<TaxonomyTermTranslation>> */
    private array $termTranslations = [];

    public function findBySlug(string $slug, ?string $tenantId = null): ?Taxonomy
    {
        foreach ($this->taxonomies as $taxonomy) {
            if ($taxonomy->slug === $slug && $taxonomy->tenantId === $tenantId) {
                return $taxonomy;
            }
        }

        return null;
    }

    public function findTerms(string $taxonomyId, string $locale, ?string $parentId = null): array
    {
        $matching = [];

        foreach ($this->terms as $term) {
            if ($term->taxonomyId !== $taxonomyId) {
                continue;
            }

            // Match parent filter: null means root terms, string means children of that parent
            if ($parentId === null && $term->parentId !== null) {
                continue;
            }

            if ($parentId !== null && $term->parentId !== $parentId) {
                continue;
            }

            // Check if translation exists for this locale
            $hasTranslation = false;

            foreach ($this->termTranslations[$term->id] ?? [] as $tt) {
                if ($tt->locale === $locale) {
                    $hasTranslation = true;
                    break;
                }
            }

            if ($hasTranslation || $this->termTranslations[$term->id] === []) {
                $matching[] = $term;
            }
        }

        usort($matching, static fn(TaxonomyTerm $a, TaxonomyTerm $b) => $a->sortOrder <=> $b->sortOrder);

        return $matching;
    }

    public function save(Taxonomy $taxonomy, array $translations): void
    {
        $this->taxonomies[$taxonomy->id] = $taxonomy;
    }

    public function saveTerm(TaxonomyTerm $term, array $translations): void
    {
        $this->terms[$term->id] = $term;
        $this->termTranslations[$term->id] = $translations;
    }

    public function updateTermParent(string $termId, string $parentId): void
    {
        if (isset($this->terms[$termId])) {
            $old = $this->terms[$termId];
            $this->terms[$termId] = clone($old, ['parentId' => $parentId]);
        }
    }
}

final class InMemoryTaxonomyService implements TaxonomyServiceInterface
{
    /** @var array<string, list<string>> */
    private array $contentTerms = [];

    public function attachTerms(string $contentId, array $termIds): void
    {
        $existing = $this->contentTerms[$contentId] ?? [];
        $this->contentTerms[$contentId] = array_values(array_unique([...$existing, ...$termIds]));
    }

    public function detachTerms(string $contentId, array $termIds): void
    {
        $existing = $this->contentTerms[$contentId] ?? [];
        $this->contentTerms[$contentId] = array_values(
            array_filter($existing, static fn(string $id) => !in_array($id, $termIds, true)),
        );
    }

    public function bulkTag(array $contentIds, string $termId): void
    {
        foreach ($contentIds as $contentId) {
            $this->attachTerms($contentId, [$termId]);
        }
    }

    public function bulkUntag(array $contentIds, string $termId): void
    {
        foreach ($contentIds as $contentId) {
            $this->detachTerms($contentId, [$termId]);
        }
    }

    /** @return list<string> */
    public function getTermsForContent(string $contentId): array
    {
        return $this->contentTerms[$contentId] ?? [];
    }
}
