<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Extension\Cms\Navigation\LinkTarget;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuItem;
use Pulsar\Extension\Cms\Navigation\MenuItemResolved;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;

#[Internal(reason: 'Raw-DB repository; use MenuRepositoryInterface for public API')]
final readonly class DbMenuRepository implements MenuRepositoryInterface
{
    private const string SENTINEL_TENANT = '00000000-0000-0000-0000-000000000000';

    private const string SQL_FIND_BY_LOCATION = <<<'SQL'
        SELECT m.*
        FROM cms_menus m
        WHERE m.location = :location
            AND COALESCE(m.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const array UPSERT_MENU_COLUMNS = ['id', 'tenant_id', 'location', 'created_at'];
    private const array UPSERT_MENU_UPDATE = ['location'];

    private const array UPSERT_MENU_TRANS_COLUMNS = ['menu_id', 'locale', 'name'];
    private const array UPSERT_MENU_TRANS_UPDATE = ['name'];

    private const array UPSERT_ITEM_COLUMNS = [
        'id', 'menu_id', 'parent_id', 'content_id', 'url', 'target',
        'css_class', 'icon', 'sort_order', 'visible',
    ];

    private const array UPSERT_ITEM_UPDATE = [
        'parent_id', 'content_id', 'url', 'target', 'css_class', 'icon', 'sort_order', 'visible',
    ];

    private const array UPSERT_ITEM_TRANS_COLUMNS = ['menu_item_id', 'locale', 'label', 'title_attr'];
    private const array UPSERT_ITEM_TRANS_UPDATE = ['label', 'title_attr'];

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findByImportId(string $importId): ?Menu
    {
        $result = $this->connection->query(
            'SELECT * FROM cms_menus WHERE import_id = :import_id LIMIT 1',
            ['import_id' => $importId],
        );
        $row = $result->first();

        return $row !== null ? new Menu(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            location: $row->getString('location'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        ) : null;
    }

    public function findItemByImportId(string $importId): ?MenuItem
    {
        $result = $this->connection->query(
            'SELECT * FROM cms_menu_items WHERE import_id = :import_id LIMIT 1',
            ['import_id' => $importId],
        );
        $row = $result->first();

        return $row !== null ? new MenuItem(
            id: $row->getString('id'),
            menuId: $row->getString('menu_id'),
            parentId: $row->getNullableString('parent_id'),
            contentId: $row->getNullableString('content_id'),
            url: $row->getNullableString('url'),
            target: LinkTarget::tryFrom($row->getNullableString('target') ?? '_self') ?? LinkTarget::Self,
            cssClass: $row->getNullableString('css_class'),
            icon: $row->getNullableString('icon'),
            sortOrder: $row->getInt('sort_order'),
            visible: $row->getBool('visible'),
        ) : null;
    }

    public function findByLocation(string $location, string $locale, ?string $tenantId = null): ?Menu
    {
        $resolved = $tenantId ?? $this->tenantId;
        $tenantKey = $resolved ?? self::SENTINEL_TENANT;

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
            $menuSql = UpsertBuilder::compile(
                $conn->driver(),
                'cms_menus',
                self::UPSERT_MENU_COLUMNS,
                ['id'],
                self::UPSERT_MENU_UPDATE,
            );

            $conn->execute($menuSql, [
                'id' => $menu->id,
                'tenant_id' => $menu->tenantId,
                'location' => $menu->location,
                'created_at' => $menu->createdAt->format('c'),
            ]);

            $transSql = UpsertBuilder::compile(
                $conn->driver(),
                'cms_menu_translations',
                self::UPSERT_MENU_TRANS_COLUMNS,
                ['menu_id', 'locale'],
                self::UPSERT_MENU_TRANS_UPDATE,
            );

            foreach ($translations as $translation) {
                $conn->execute($transSql, [
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
            $itemSql = UpsertBuilder::compile(
                $conn->driver(),
                'cms_menu_items',
                self::UPSERT_ITEM_COLUMNS,
                ['id'],
                self::UPSERT_ITEM_UPDATE,
            );

            $conn->execute($itemSql, [
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

            $transSql = UpsertBuilder::compile(
                $conn->driver(),
                'cms_menu_item_translations',
                self::UPSERT_ITEM_TRANS_COLUMNS,
                ['menu_item_id', 'locale'],
                self::UPSERT_ITEM_TRANS_UPDATE,
            );

            foreach ($translations as $translation) {
                $conn->execute($transSql, [
                    'menu_item_id' => $translation->menuItemId,
                    'locale' => $translation->locale,
                    'label' => $translation->label,
                    'title_attr' => $translation->titleAttr,
                ]);
            }
        });
    }

    public function findItemsByMenu(string $menuId, string $locale): array
    {
        $sql = <<<'SQL'
            SELECT
                i.id, i.menu_id, i.parent_id, i.content_id, i.url,
                i.target, i.css_class, i.icon, i.sort_order, i.visible,
                COALESCE(t.label, tf.label, '') AS label,
                COALESCE(t.title_attr, tf.title_attr) AS title_attr,
                ct.path AS content_path
            FROM cms_menu_items i
            LEFT JOIN cms_menu_item_translations t
                ON t.menu_item_id = i.id AND t.locale = :locale
            LEFT JOIN cms_menu_item_translations tf
                ON tf.menu_item_id = i.id AND tf.locale = (
                    SELECT MIN(locale) FROM cms_menu_item_translations WHERE menu_item_id = i.id
                )
            LEFT JOIN cms_content_translations ct
                ON ct.content_id = i.content_id AND ct.locale = :locale2
            WHERE i.menu_id = :menu_id
                AND i.visible = true
            ORDER BY i.sort_order ASC
            SQL;

        $result = $this->connection->query($sql, [
            'menu_id' => $menuId,
            'locale' => $locale,
            'locale2' => $locale,
        ]);

        $items = [];

        foreach ($result->rows as $row) {
            $items[] = new MenuItemResolved(
                id: $row->getString('id'),
                menuId: $row->getString('menu_id'),
                parentId: $row->getNullableString('parent_id'),
                contentId: $row->getNullableString('content_id'),
                url: $row->getNullableString('url'),
                label: $row->getString('label'),
                titleAttr: $row->getNullableString('title_attr'),
                target: LinkTarget::tryFrom($row->getString('target')) ?? LinkTarget::Self,
                cssClass: $row->getNullableString('css_class'),
                icon: $row->getNullableString('icon'),
                sortOrder: $row->getInt('sort_order'),
                visible: true,
                contentPath: $row->getNullableString('content_path'),
            );
        }

        return $items;
    }
}
