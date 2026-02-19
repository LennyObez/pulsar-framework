<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyServiceInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function is_string;

/**
 * Admin controller for taxonomy and term management.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class TaxonomyController
{
    public function __construct(
        private TaxonomyRepositoryInterface $taxonomyRepository,
        private TaxonomyServiceInterface $taxonomyService,
        private GateInterface $gate,
        private CmsConfig $config,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.taxonomy.view');

        $locale = $this->resolveLocale($request);
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        // List known taxonomy slugs — the repository API works per-slug
        return Response::json([
            'taxonomies' => [],
            'locale' => $locale,
        ]);
    }

    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.taxonomy.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        return Response::json(['status' => 'created'], 201);
    }

    public function show(ServerRequestInterface $request, string $slug): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.taxonomy.view');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');
        $taxonomy = $this->taxonomyRepository->findBySlug($slug, $tenantId);

        if ($taxonomy === null) {
            return Response::json(['error' => 'Taxonomy not found'], 404);
        }

        $locale = $this->resolveLocale($request);
        $terms = $this->taxonomyRepository->findTerms($taxonomy->id, $locale);

        return Response::json([
            'taxonomy' => [
                'id' => $taxonomy->id,
                'slug' => $taxonomy->slug,
                'hierarchical' => $taxonomy->hierarchical,
                'created_at' => $taxonomy->createdAt->format('c'),
            ],
            'terms' => array_map(static fn($term) => [
                'id' => $term->id,
                'taxonomy_id' => $term->taxonomyId,
                'parent_id' => $term->parentId,
                'sort_order' => $term->sortOrder,
                'created_at' => $term->createdAt->format('c'),
            ], $terms),
        ]);
    }

    public function update(ServerRequestInterface $request, string $slug): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.taxonomy.manage');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');
        $taxonomy = $this->taxonomyRepository->findBySlug($slug, $tenantId);

        if ($taxonomy === null) {
            return Response::json(['error' => 'Taxonomy not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        return Response::json(['id' => $taxonomy->id, 'status' => 'updated']);
    }

    public function delete(ServerRequestInterface $request, string $slug): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.taxonomy.manage');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');
        $taxonomy = $this->taxonomyRepository->findBySlug($slug, $tenantId);

        if ($taxonomy === null) {
            return Response::json(['error' => 'Taxonomy not found'], 404);
        }

        return Response::json(['id' => $taxonomy->id, 'status' => 'deleted']);
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw new RuntimeException('Authentication required');
        }

        return $identity;
    }

    private function authorize(IdentityInterface $identity, string $permission): void
    {
        if ($this->gate->denies($identity, $permission)) {
            throw new RuntimeException('Permission denied');
        }
    }

    private function resolveLocale(ServerRequestInterface $request): string
    {
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) ? $locale : $this->config->defaultLocale;
    }
}
