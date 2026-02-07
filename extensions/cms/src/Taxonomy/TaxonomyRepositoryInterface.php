<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Taxonomy;

use Pulsar\Api\Api;

/**
 * Persistence interface for taxonomy aggregates and terms.
 */
#[Api(since: '1.0.0')]
interface TaxonomyRepositoryInterface
{
    /**
     * Find a taxonomy by its slug within a tenant scope.
     */
    public function findBySlug(string $slug, ?string $tenantId = null): ?Taxonomy;

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
}
