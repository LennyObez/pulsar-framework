<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_products (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                tenant_key VARCHAR(36) NOT NULL GENERATED ALWAYS AS (
                    COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000')
                ) STORED,
                sku VARCHAR(100) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                price_amount BIGINT NOT NULL,
                price_currency VARCHAR(3) NOT NULL,
                tax_category VARCHAR(50) DEFAULT NULL,
                stock_quantity INTEGER DEFAULT NULL,
                digital BOOLEAN NOT NULL DEFAULT false,
                content_id VARCHAR(36) DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_product_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE SET NULL,
                CONSTRAINT chk_product_status CHECK (status IN ('draft', 'active', 'archived')),
                CONSTRAINT chk_product_price_non_negative CHECK (price_amount >= 0)
            )
            SQL, $driver));

        $indexes->ensure(
            'cms_products',
            'uq_product_tenant_sku',
            ['tenant_key', 'sku'],
            unique: true,
        );

        $indexes->ensure('cms_products', 'idx_product_status_tenant', ['status', 'tenant_id']);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_product_translations (
                id VARCHAR(36) NOT NULL,
                product_id VARCHAR(36) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                name VARCHAR(300) NOT NULL,
                description TEXT DEFAULT NULL,
                slug VARCHAR(300) DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_product_translation FOREIGN KEY (product_id) REFERENCES cms_products (id) ON DELETE CASCADE
            )
            SQL);

        $indexes->ensure(
            'cms_product_translations',
            'uq_product_translation_locale',
            ['product_id', 'locale'],
            unique: true,
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_product_translations');
        $connection->execute('DROP TABLE IF EXISTS cms_products');
    }
};
