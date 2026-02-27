<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Navigation;

use Pulsar\Api\Api;

/**
 * Persistence interface for navigation menus and menu items.
 *
 * @psalm-api Public binding contract; implemented by DbMenuRepository
 *            and consumed by navigation services and admin controllers.
 */
#[Api(since: '1.0.0')]
interface MenuRepositoryInterface
{
    /**
     * Find a menu by its location, resolving translations for the given locale.
     */
    public function findByLocation(string $location, string $locale, ?string $tenantId = null): ?Menu;

    /**
     * Find a menu by its stable import identifier for idempotent imports.
     */
    public function findByImportId(string $importId): ?Menu;

    /**
     * Find a menu item by its stable import identifier for idempotent imports.
     */
    public function findItemByImportId(string $importId): ?MenuItem;

    /**
     * Load all items for a menu, resolved for the given locale.
     *
     * Items are returned in sort_order, with their locale-specific
     * label and title attribute resolved from translations.
     *
     * @return list<MenuItemResolved>
     */
    public function findItemsByMenu(string $menuId, string $locale): array;

    /**
     * Persist a menu and its translations.
     *
     * @param list<MenuTranslation> $translations
     */
    public function save(Menu $menu, array $translations): void;

    /**
     * Persist a menu item and its translations.
     *
     * @param list<MenuItemTranslation> $translations
     */
    public function saveItem(MenuItem $item, array $translations): void;
}
