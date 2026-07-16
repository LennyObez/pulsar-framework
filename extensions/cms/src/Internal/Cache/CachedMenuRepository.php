<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Cache;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuItem;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;

/**
 * @psalm-api Caching decorator wrapping the underlying MenuRepositoryInterface
 *            implementation; bound by the CMS service provider, not instantiated by name.
 */
#[Internal(reason: 'Caching decorator for menus; use MenuRepositoryInterface')]
final readonly class CachedMenuRepository implements MenuRepositoryInterface
{
    private const int TTL = 300;

    public function __construct(
        private MenuRepositoryInterface $inner,
        private TaggedCacheInterface $cache,
    ) {}

    #[Override]
    public function findByImportId(string $importId): ?Menu
    {
        return $this->inner->findByImportId($importId);
    }

    #[Override]
    public function findItemByImportId(string $importId): ?MenuItem
    {
        return $this->inner->findItemByImportId($importId);
    }

    #[Override]
    public function findByLocation(string $location, string $locale, ?string $tenantId = null): ?Menu
    {
        $cacheKey = CmsCacheKeys::menu($location, $locale, $tenantId);
        $tag = CmsCacheKeys::menuTag($location);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            /** @var Menu $cached */
            return $cached;
        }

        $menu = $this->inner->findByLocation($location, $locale, $tenantId);

        if ($menu !== null) {
            $this->cache->set($cacheKey, $menu, [$tag, CmsCacheKeys::TAG_ALL_MENUS], self::TTL);
        }

        return $menu;
    }

    #[Override]
    public function findItemsByMenu(string $menuId, string $locale): array
    {
        $cacheKey = CmsCacheKeys::menuItems($menuId, $locale);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            /** @var list<\Pulsar\Extension\Cms\Navigation\MenuItemResolved> $cached */
            return $cached;
        }

        $items = $this->inner->findItemsByMenu($menuId, $locale);

        if ($items !== []) {
            $this->cache->set($cacheKey, $items, [CmsCacheKeys::TAG_ALL_MENUS, CmsCacheKeys::TAG_MENU_ITEMS], self::TTL);
        }

        return $items;
    }

    #[Override]
    public function save(Menu $menu, array $translations): void
    {
        $this->inner->save($menu, $translations);
        $this->cache->invalidateTag(CmsCacheKeys::menuTag($menu->location));
    }

    #[Override]
    public function saveItem(MenuItem $item, array $translations): void
    {
        $this->inner->saveItem($item, $translations);
        // Invalidate all menus since items can affect any menu
        $this->cache->invalidateTag(CmsCacheKeys::TAG_ALL_MENUS);
    }
}
