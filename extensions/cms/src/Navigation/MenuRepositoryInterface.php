<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Navigation;

use Pulsar\Api\Api;

/**
 * Persistence interface for navigation menus and menu items.
 */
#[Api(since: '1.0.0')]
interface MenuRepositoryInterface
{
    /**
     * Find a menu by its location, resolving translations for the given locale.
     */
    public function findByLocation(string $location, string $locale, ?string $tenantId = null): ?Menu;

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
