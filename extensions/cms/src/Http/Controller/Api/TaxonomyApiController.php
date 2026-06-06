<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTerm;
use Pulsar\Http\Message\Response;

use function array_map;
use function is_string;

/**
 * Public REST API controller for CMS taxonomies.
 *
 * Provides read-only JSON endpoints for taxonomy and term retrieval.
 */
#[Internal(reason: 'CMS REST API controller; implementation detail')]
final readonly class TaxonomyApiController
{
    public function __construct(
        private TaxonomyRepositoryInterface $repository,
        private CmsConfig $config,
    ) {}

    /**
     * GET /api/v1/taxonomies/{slug}: Show a single taxonomy by slug.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function show(ServerRequestInterface $request, string $slug): Response
    {
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $taxonomy = $this->repository->findBySlug($slug, $tenantId);

        if ($taxonomy === null) {
            return Response::json(['error' => 'Taxonomy not found', 'status' => 404], 404);
        }

        return Response::json([
            'data' => [
                'id' => $taxonomy->id,
                'slug' => $taxonomy->slug,
                'hierarchical' => $taxonomy->hierarchical,
                'created_at' => $taxonomy->createdAt->format('c'),
            ],
        ]);
    }

    /**
     * GET /api/v1/taxonomies/{slug}/terms: List terms for a taxonomy.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function terms(ServerRequestInterface $request, string $slug): Response
    {
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $taxonomy = $this->repository->findBySlug($slug, $tenantId);

        if ($taxonomy === null) {
            return Response::json(['error' => 'Taxonomy not found', 'status' => 404], 404);
        }

        $params = $request->getQueryParams();
        /** @var mixed $rawLocale */
        $rawLocale = $params['locale'] ?? null;
        $locale = is_string($rawLocale) ? $rawLocale : $this->config->defaultLocale;
        /** @var mixed $rawParentId */
        $rawParentId = $params['parent_id'] ?? null;
        $parentId = is_string($rawParentId) ? $rawParentId : null;

        $terms = $this->repository->findTerms($taxonomy->id, $locale, $parentId);

        return Response::json([
            'data' => array_map(static fn(TaxonomyTerm $term) => [
                'id' => $term->id,
                'taxonomy_id' => $term->taxonomyId,
                'parent_id' => $term->parentId,
                'sort_order' => $term->sortOrder,
                'created_at' => $term->createdAt->format('c'),
            ], $terms),
        ]);
    }
}
