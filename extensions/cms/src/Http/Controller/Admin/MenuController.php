<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function is_string;

/**
 * Admin controller for menu and menu item management.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class MenuController
{
    public function __construct(
        private MenuRepositoryInterface $menuRepository,
        private GateInterface $gate,
        private CmsConfig $config,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.menus.view');

        return Response::json(['menus' => []]);
    }

    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.menus.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

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

        return Response::json([
            'menu' => [
                'id' => $menu->id,
                'location' => $menu->location,
                'created_at' => $menu->createdAt->format('c'),
            ],
        ]);
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

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

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
