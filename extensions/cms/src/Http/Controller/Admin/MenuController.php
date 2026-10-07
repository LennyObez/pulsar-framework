<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_string;

/**
 * Admin controller for menu and menu item management.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class MenuController extends AbstractAdminController
{
    public function __construct(
        private MenuRepositoryInterface $menuRepository,
        private CmsConfig $config,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.menus.view');

        $data = ['menus' => []];

        return $this->respondWithView($request, 'admin.menus.index', $data);
    }

    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.menus.manage');

        return Response::json(['status' => 'created'], 201);
    }

    public function show(ServerRequestInterface $request, string $location): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.menus.view');

        $locale = $this->resolveLocale($request);
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $menu = $this->menuRepository->findByLocation($location, $locale, $tenantId);

        if ($menu === null) {
            return Response::json(['error' => 'Menu not found'], 404);
        }

        $data = [
            'menu' => [
                'id' => $menu->id,
                'location' => $menu->location,
                'created_at' => $menu->createdAt->format('c'),
            ],
        ];

        return $this->respondWithView($request, 'admin.menus.form', $data);
    }

    public function update(ServerRequestInterface $request, string $location): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.menus.manage');

        $locale = $this->resolveLocale($request);
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $menu = $this->menuRepository->findByLocation($location, $locale, $tenantId);

        if ($menu === null) {
            return Response::json(['error' => 'Menu not found'], 404);
        }

        return Response::json(['id' => $menu->id, 'status' => 'updated']);
    }

    public function delete(ServerRequestInterface $request, string $location): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.menus.manage');

        $locale = $this->resolveLocale($request);
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $menu = $this->menuRepository->findByLocation($location, $locale, $tenantId);

        if ($menu === null) {
            return Response::json(['error' => 'Menu not found'], 404);
        }

        return Response::json(['id' => $menu->id, 'status' => 'deleted']);
    }

    private function resolveLocale(ServerRequestInterface $request): string
    {
        /** @var mixed $locale */
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) ? $locale : $this->config->defaultLocale;
    }
}
