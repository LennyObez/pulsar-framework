<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_menus (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                location VARCHAR(100) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id)
            )
            SQL, $driver));

        // Expression index with COALESCE: PostgreSQL + SQLite support it;
        // MySQL fallback uses a standard composite index
        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX uq_menu_location_tenant
                    ON cms_menus (tenant_id, location)
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uq_menu_location_tenant
                    ON cms_menus (COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'), location)
                SQL);
        }

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_menu_translations (
                menu_id VARCHAR(36) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                name VARCHAR(200) NOT NULL,
                PRIMARY KEY (menu_id, locale),
                CONSTRAINT fk_menu_translation FOREIGN KEY (menu_id) REFERENCES cms_menus (id) ON DELETE CASCADE
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_menu_items (
                id VARCHAR(36) NOT NULL,
                menu_id VARCHAR(36) NOT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                content_id VARCHAR(36) DEFAULT NULL,
                url VARCHAR(2000) DEFAULT NULL,
                target VARCHAR(10) NOT NULL DEFAULT '_self',
                css_class VARCHAR(200) DEFAULT NULL,
                icon VARCHAR(100) DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                visible BOOLEAN NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id),
                CONSTRAINT fk_menu_item_menu FOREIGN KEY (menu_id) REFERENCES cms_menus (id) ON DELETE CASCADE,
                CONSTRAINT fk_menu_item_parent FOREIGN KEY (parent_id) REFERENCES cms_menu_items (id) ON DELETE SET NULL,
                CONSTRAINT fk_menu_item_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE SET NULL,
                CONSTRAINT chk_menu_item_link_type CHECK (
                    (content_id IS NOT NULL AND url IS NULL)
                    OR (content_id IS NULL AND url IS NOT NULL)
                    OR (content_id IS NULL AND url IS NULL)
                )
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_menu_item_menu_sort
                ON cms_menu_items (menu_id, parent_id, sort_order)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_menu_item_translations (
                menu_item_id VARCHAR(36) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                label VARCHAR(200) NOT NULL,
                title_attr VARCHAR(200) DEFAULT NULL,
                PRIMARY KEY (menu_item_id, locale),
                CONSTRAINT fk_menu_item_translation FOREIGN KEY (menu_item_id) REFERENCES cms_menu_items (id) ON DELETE CASCADE
            )
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_menu_item_translations');
        $connection->execute('DROP TABLE IF EXISTS cms_menu_items');
        $connection->execute('DROP TABLE IF EXISTS cms_menu_translations');
        $connection->execute('DROP TABLE IF EXISTS cms_menus');
    }
};
