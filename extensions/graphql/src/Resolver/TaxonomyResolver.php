<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Resolver;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;

/**
 * Resolves GraphQL queries for Taxonomy and TaxonomyTerm types.
 */
#[Api(since: '1.0.0')]
final readonly class TaxonomyResolver
{
    public function __construct(
        private TaxonomyRepositoryInterface $taxonomyRepository,
    ) {}

    /**
     * Resolve a taxonomy by slug.
     *
     * @return array<string, mixed>|null
     */
    public function resolveBySlug(string $slug): ?array
    {
        $taxonomy = $this->taxonomyRepository->findBySlug($slug);

        if ($taxonomy === null) {
            return null;
        }

        return [
            'id' => $taxonomy->id,
            'tenantId' => $taxonomy->tenantId,
            'slug' => $taxonomy->slug,
            'hierarchical' => $taxonomy->hierarchical,
            'createdAt' => $taxonomy->createdAt->format('c'),
        ];
    }

    /**
     * Resolve terms for a taxonomy in a given locale.
     *
     * @return list<array<string, mixed>>
     */
    public function resolveTerms(string $taxonomyId, string $locale): array
    {
        $terms = $this->taxonomyRepository->findTerms($taxonomyId, $locale);
        $result = [];

        foreach ($terms as $term) {
            $result[] = [
                'id' => $term->id,
                'taxonomyId' => $term->taxonomyId,
                'parentId' => $term->parentId,
                'sortOrder' => $term->sortOrder,
                'createdAt' => $term->createdAt->format('c'),
            ];
        }

        return $result;
    }
}
