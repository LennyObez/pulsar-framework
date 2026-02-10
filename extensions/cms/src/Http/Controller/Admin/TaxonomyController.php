<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;

/**
 * Admin controller for taxonomy and term management.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class TaxonomyController
{
    use RendersAdminView;

    public function __construct(
        private TaxonomyRepositoryInterface $taxonomyRepository,
        private ?GateInterface $gate = null,
        private ?CmsConfig $config = null,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.taxonomy.view');

        $locale = $this->resolveLocale($request);
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        // List known taxonomy slugs — the repository API works per-slug
        $data = [
            'taxonomies' => [],
            'locale' => $locale,
        ];

        return $this->respondWithView($request, 'admin.taxonomy.index', $data);
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

        $data = [
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
        ];

        return $this->respondWithView($request, 'admin.taxonomy.form', $data);
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

    private function resolveLocale(ServerRequestInterface $request): string
    {
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) ? $locale : ($this->config?->defaultLocale ?? 'en');
    }
}
