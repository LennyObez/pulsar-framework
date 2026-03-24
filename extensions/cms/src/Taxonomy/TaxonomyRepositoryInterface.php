<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Taxonomy;

use Pulsar\Api\Api;

/**
 * Persistence interface for taxonomy aggregates and terms.
 *
 * @psalm-api Public binding contract; implemented by DbTaxonomyRepository
 *            and consumed by TaxonomyService and admin controllers.
 */
#[Api(since: '1.0.0')]
interface TaxonomyRepositoryInterface
{
    /**
     * Find a taxonomy by its slug within a tenant scope.
     */
    public function findBySlug(string $slug, ?string $tenantId = null): ?Taxonomy;

    /**
     * Find a taxonomy by its stable import identifier for idempotent imports.
     */
    public function findByImportId(string $importId): ?Taxonomy;

    /**
     * Find a taxonomy term by its stable import identifier for idempotent imports.
     */
    public function findTermByImportId(string $importId): ?TaxonomyTerm;

    /**
     * Find terms for a taxonomy, optionally filtered by locale and parent.
     *
     * @return list<TaxonomyTerm>
     */
    public function findTerms(string $taxonomyId, string $locale, ?string $parentId = null): array;

    /**
     * Persist a taxonomy and its translations.
     *
     * @param list<TaxonomyTranslation> $translations
     */
    public function save(Taxonomy $taxonomy, array $translations): void;

    /**
     * Persist a taxonomy term and its translations.
     *
     * @param list<TaxonomyTermTranslation> $translations
     */
    public function saveTerm(TaxonomyTerm $term, array $translations): void;

    /**
     * Update a term's parent ID for hierarchy preservation during import.
     */
    public function updateTermParent(string $termId, string $parentId): void;
}
