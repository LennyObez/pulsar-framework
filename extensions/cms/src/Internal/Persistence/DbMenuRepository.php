<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuItem;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;

#[Internal(reason: 'Raw-DB repository — use MenuRepositoryInterface for public API')]
final readonly class DbMenuRepository implements MenuRepositoryInterface
{
    private const string SENTINEL_TENANT = '00000000-0000-0000-0000-000000000000';

    private const string SQL_FIND_BY_LOCATION = <<<'SQL'
        SELECT m.*
        FROM cms_menus m
        WHERE m.location = :location
            AND COALESCE(m.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_UPSERT_MENU = <<<'SQL'
        INSERT INTO cms_menus (id, tenant_id, location, created_at)
        VALUES (:id, :tenant_id, :location, :created_at)
        ON CONFLICT (id) DO UPDATE SET
            location = EXCLUDED.location
        SQL;

    private const string SQL_UPSERT_MENU_TRANSLATION = <<<'SQL'
        INSERT INTO cms_menu_translations (menu_id, locale, name)
        VALUES (:menu_id, :locale, :name)
        ON CONFLICT (menu_id, locale) DO UPDATE SET
            name = EXCLUDED.name
        SQL;

    private const string SQL_UPSERT_MENU_ITEM = <<<'SQL'
        INSERT INTO cms_menu_items (
            id, menu_id, parent_id, content_id, url, target,
            css_class, icon, sort_order, visible
        ) VALUES (
            :id, :menu_id, :parent_id, :content_id, :url, :target,
            :css_class, :icon, :sort_order, :visible
        )
        ON CONFLICT (id) DO UPDATE SET
            parent_id = EXCLUDED.parent_id,
            content_id = EXCLUDED.content_id,
            url = EXCLUDED.url,
            target = EXCLUDED.target,
            css_class = EXCLUDED.css_class,
            icon = EXCLUDED.icon,
            sort_order = EXCLUDED.sort_order,
            visible = EXCLUDED.visible
        SQL;

    private const string SQL_UPSERT_MENU_ITEM_TRANSLATION = <<<'SQL'
        INSERT INTO cms_menu_item_translations (menu_item_id, locale, label, title_attr)
        VALUES (:menu_item_id, :locale, :label, :title_attr)
        ON CONFLICT (menu_item_id, locale) DO UPDATE SET
            label = EXCLUDED.label,
            title_attr = EXCLUDED.title_attr
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findByLocation(string $location, string $locale, ?string $tenantId = null): ?Menu
    {
        $tenantKey = ($tenantId ?? $this->tenantId) ?? self::SENTINEL_TENANT;

        $result = $this->connection->query(self::SQL_FIND_BY_LOCATION, [
            'location' => $location,
            'tenant_key' => $tenantKey,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return new Menu(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            location: $row->getString('location'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }

    public function save(Menu $menu, array $translations): void
    {
        $this->connection->transaction(function (ConnectionInterface $conn) use ($menu, $translations): void {
            $conn->execute(self::SQL_UPSERT_MENU, [
                'id' => $menu->id,
                'tenant_id' => $menu->tenantId,
                'location' => $menu->location,
                'created_at' => $menu->createdAt->format('c'),
            ]);

            foreach ($translations as $translation) {
                $conn->execute(self::SQL_UPSERT_MENU_TRANSLATION, [
                    'menu_id' => $translation->menuId,
                    'locale' => $translation->locale,
                    'name' => $translation->name,
                ]);
            }
        });
    }

    public function saveItem(MenuItem $item, array $translations): void
    {
        $this->connection->transaction(function (ConnectionInterface $conn) use ($item, $translations): void {
            $conn->execute(self::SQL_UPSERT_MENU_ITEM, [
                'id' => $item->id,
                'menu_id' => $item->menuId,
                'parent_id' => $item->parentId,
                'content_id' => $item->contentId,
                'url' => $item->url,
                'target' => $item->target->value,
                'css_class' => $item->cssClass,
                'icon' => $item->icon,
                'sort_order' => $item->sortOrder,
                'visible' => $item->visible,
            ]);

            foreach ($translations as $translation) {
                $conn->execute(self::SQL_UPSERT_MENU_ITEM_TRANSLATION, [
                    'menu_item_id' => $translation->menuItemId,
                    'locale' => $translation->locale,
                    'label' => $translation->label,
                    'title_attr' => $translation->titleAttr,
                ]);
            }
        });
    }
}
