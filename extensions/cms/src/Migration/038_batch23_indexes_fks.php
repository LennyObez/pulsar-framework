<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        match ($connection->driver()) {
            Driver::SQLite => $this->upSqlite($connection),
            Driver::MySQL => $this->upMysql($connection),
            Driver::PostgreSQL => $this->upPostgresql($connection),
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        // Map each index to its correct table
        $indexTableMap = [
            'idx_contents_tenant_status' => 'cms_contents',
            'idx_contents_author_created' => 'cms_contents',
            'idx_contents_published_at' => 'cms_contents',
            'idx_translations_path_locale' => 'cms_content_translations',
            'idx_comments_content_status' => 'cms_comments',
            'idx_comments_author' => 'cms_comments',
            'idx_orders_customer_created' => 'cms_orders',
            'idx_orders_status_created' => 'cms_orders',
            'idx_order_items_order' => 'cms_order_items',
            'idx_products_tenant_status' => 'cms_products',
            'idx_products_sku' => 'cms_products',
            'idx_product_variants_product' => 'cms_product_variants',
            'idx_promotions_active_dates' => 'cms_promotions',
            'idx_media_assets_uploader' => 'cms_media_assets',
            'idx_media_assets_visibility' => 'cms_media_assets',
            'idx_customers_user' => 'cms_customers',
            'idx_customers_email' => 'cms_customers',
            'idx_redirects_tenant_path' => 'cms_redirects',
            'idx_coupons_promotion' => 'cms_coupons',
        ];

        foreach ($indexTableMap as $index => $table) {
            if ($driver === Driver::MySQL) {
                $connection->execute("DROP INDEX IF EXISTS $index ON $table");
            } else {
                $connection->execute("DROP INDEX IF EXISTS $index");
            }
        }

        // Drop foreign keys added in up()
        if ($driver === Driver::MySQL) {
            $fkTableMap = [
                'fk_order_items_order' => 'cms_order_items',
                'fk_product_variants_product' => 'cms_product_variants',
                'fk_coupons_promotion' => 'cms_coupons',
                'fk_translations_content' => 'cms_content_translations',
            ];

            foreach ($fkTableMap as $fk => $table) {
                $result = $connection->query(
                    "SELECT COUNT(*) AS cnt FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_name = :name AND constraint_type = 'FOREIGN KEY'",
                    ['name' => $fk],
                );

                if (($result->first()?->getInt('cnt') ?? 0) > 0) {
                    $connection->execute("ALTER TABLE $table DROP FOREIGN KEY $fk");
                }
            }
        } elseif ($driver === Driver::PostgreSQL) {
            $fkTableMap = [
                'fk_order_items_order' => 'cms_order_items',
                'fk_product_variants_product' => 'cms_product_variants',
                'fk_coupons_promotion' => 'cms_coupons',
                'fk_translations_content' => 'cms_content_translations',
            ];

            foreach ($fkTableMap as $fk => $table) {
                $connection->execute("ALTER TABLE $table DROP CONSTRAINT IF EXISTS $fk");
            }
        }
    }

    private function upSqlite(ConnectionInterface $connection): void
    {
        // Content performance indexes
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_contents_tenant_status ON cms_contents (tenant_id, status, deleted_at)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_contents_author_created ON cms_contents (author_id, created_at)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_contents_published_at ON cms_contents (published_at) WHERE published_at IS NOT NULL');

        // Translation path lookups
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_translations_path_locale ON cms_content_translations (path, locale)');

        // Comment performance
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_comments_content_status ON cms_comments (content_id, status, deleted_at)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_comments_author ON cms_comments (author_id)');

        // Order performance
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_orders_customer_created ON cms_orders (customer_id, created_at)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_orders_status_created ON cms_orders (status, created_at)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_order_items_order ON cms_order_items (order_id)');

        // Product performance
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_products_tenant_status ON cms_products (tenant_id, status)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_products_sku ON cms_products (sku)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_product_variants_product ON cms_product_variants (product_id, sort_order)');

        // Promotion performance
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_promotions_active_dates ON cms_promotions (is_active, starts_at, expires_at)');

        // Media performance
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_media_assets_uploader ON cms_media_assets (uploader_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_media_assets_visibility ON cms_media_assets (visibility, deleted_at)');

        // Customer performance
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_customers_user ON cms_customers (user_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_customers_email ON cms_customers (email)');

        // Redirect performance
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_redirects_tenant_path ON cms_redirects (tenant_id, from_path) WHERE deleted_at IS NULL');

        // Coupon performance
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_coupons_promotion ON cms_coupons (promotion_id)');
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        $this->createIndexIfNotExists($connection, 'idx_contents_tenant_status', 'cms_contents', '(tenant_id, status, deleted_at)');
        $this->createIndexIfNotExists($connection, 'idx_contents_author_created', 'cms_contents', '(author_id, created_at)');
        $this->createIndexIfNotExists($connection, 'idx_contents_published_at', 'cms_contents', '(published_at)');

        $this->createIndexIfNotExists($connection, 'idx_translations_path_locale', 'cms_content_translations', '(path, locale)');

        $this->createIndexIfNotExists($connection, 'idx_comments_content_status', 'cms_comments', '(content_id, status, deleted_at)');
        $this->createIndexIfNotExists($connection, 'idx_comments_author', 'cms_comments', '(author_id)');

        $this->createIndexIfNotExists($connection, 'idx_orders_customer_created', 'cms_orders', '(customer_id, created_at)');
        $this->createIndexIfNotExists($connection, 'idx_orders_status_created', 'cms_orders', '(status, created_at)');
        $this->createIndexIfNotExists($connection, 'idx_order_items_order', 'cms_order_items', '(order_id)');

        $this->createIndexIfNotExists($connection, 'idx_products_tenant_status', 'cms_products', '(tenant_id, status)');
        $this->createIndexIfNotExists($connection, 'idx_products_sku', 'cms_products', '(sku)');
        $this->createIndexIfNotExists($connection, 'idx_product_variants_product', 'cms_product_variants', '(product_id, sort_order)');

        $this->createIndexIfNotExists($connection, 'idx_promotions_active_dates', 'cms_promotions', '(is_active, starts_at, expires_at)');

        $this->createIndexIfNotExists($connection, 'idx_media_assets_uploader', 'cms_media_assets', '(uploader_id)');
        $this->createIndexIfNotExists($connection, 'idx_media_assets_visibility', 'cms_media_assets', '(visibility, deleted_at)');

        $this->createIndexIfNotExists($connection, 'idx_customers_user', 'cms_customers', '(user_id)');
        $this->createIndexIfNotExists($connection, 'idx_customers_email', 'cms_customers', '(email)');

        $this->createIndexIfNotExists($connection, 'idx_redirects_tenant_path', 'cms_redirects', '(tenant_id, from_path)');

        $this->createIndexIfNotExists($connection, 'idx_coupons_promotion', 'cms_coupons', '(promotion_id)');

        // Foreign keys (MySQL supports ALTER TABLE ADD CONSTRAINT IF NOT EXISTS since 8.0.29)
        $this->addForeignKeyIfNotExists($connection, 'fk_order_items_order', 'cms_order_items', 'order_id', 'cms_orders');
        $this->addForeignKeyIfNotExists($connection, 'fk_product_variants_product', 'cms_product_variants', 'product_id', 'cms_products');
        $this->addForeignKeyIfNotExists($connection, 'fk_coupons_promotion', 'cms_coupons', 'promotion_id', 'cms_promotions');
        $this->addForeignKeyIfNotExists($connection, 'fk_translations_content', 'cms_content_translations', 'content_id', 'cms_contents');
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        // Partial indexes for common queries
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_contents_tenant_status ON cms_contents (tenant_id, status) WHERE deleted_at IS NULL');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_contents_author_created ON cms_contents (author_id, created_at)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_contents_published_at ON cms_contents (published_at) WHERE published_at IS NOT NULL');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_translations_path_locale ON cms_content_translations (path, locale)');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_comments_content_status ON cms_comments (content_id, status) WHERE deleted_at IS NULL');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_comments_author ON cms_comments (author_id)');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_orders_customer_created ON cms_orders (customer_id, created_at)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_orders_status_created ON cms_orders (status, created_at)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_order_items_order ON cms_order_items (order_id)');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_products_tenant_status ON cms_products (tenant_id, status)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_products_sku ON cms_products (sku)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_product_variants_product ON cms_product_variants (product_id, sort_order)');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_promotions_active_dates ON cms_promotions (is_active, starts_at, expires_at)');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_media_assets_uploader ON cms_media_assets (uploader_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_media_assets_visibility ON cms_media_assets (visibility) WHERE deleted_at IS NULL');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_customers_user ON cms_customers (user_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_customers_email ON cms_customers (email)');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_redirects_tenant_path ON cms_redirects (tenant_id, from_path) WHERE deleted_at IS NULL');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_coupons_promotion ON cms_coupons (promotion_id)');

        // Foreign keys
        $this->addForeignKeyIfNotExistsPg($connection, 'fk_order_items_order', 'cms_order_items', 'order_id', 'cms_orders');
        $this->addForeignKeyIfNotExistsPg($connection, 'fk_product_variants_product', 'cms_product_variants', 'product_id', 'cms_products');
        $this->addForeignKeyIfNotExistsPg($connection, 'fk_coupons_promotion', 'cms_coupons', 'promotion_id', 'cms_promotions');
        $this->addForeignKeyIfNotExistsPg($connection, 'fk_translations_content', 'cms_content_translations', 'content_id', 'cms_contents');
    }

    private function createIndexIfNotExists(
        ConnectionInterface $connection,
        string $indexName,
        string $table,
        string $columns,
    ): void {
        $result = $connection->query(
            'SELECT COUNT(*) AS cnt FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = :name',
            ['name' => $indexName],
        );

        if (($result->first()?->getInt('cnt') ?? 0) === 0) {
            $connection->execute("CREATE INDEX $indexName ON $table $columns");
        }
    }

    private function addForeignKeyIfNotExists(
        ConnectionInterface $connection,
        string $constraintName,
        string $table,
        string $column,
        string $refTable,
    ): void {
        $result = $connection->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_name = :name AND constraint_type = 'FOREIGN KEY'",
            ['name' => $constraintName],
        );

        if (($result->first()?->getInt('cnt') ?? 0) === 0) {
            $connection->execute("ALTER TABLE $table ADD CONSTRAINT $constraintName FOREIGN KEY ($column) REFERENCES $refTable (id)");
        }
    }

    private function addForeignKeyIfNotExistsPg(
        ConnectionInterface $connection,
        string $constraintName,
        string $table,
        string $column,
        string $refTable,
    ): void {
        $result = $connection->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.table_constraints WHERE constraint_name = :name AND constraint_type = 'FOREIGN KEY'",
            ['name' => $constraintName],
        );

        if (($result->first()?->getInt('cnt') ?? 0) === 0) {
            $connection->execute("ALTER TABLE $table ADD CONSTRAINT $constraintName FOREIGN KEY ($column) REFERENCES $refTable (id)");
        }
    }
};
