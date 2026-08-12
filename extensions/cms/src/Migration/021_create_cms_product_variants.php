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
            CREATE TABLE IF NOT EXISTS cms_product_variants (
                id VARCHAR(36) NOT NULL,
                product_id VARCHAR(36) NOT NULL,
                sku_suffix VARCHAR(50) NOT NULL,
                attribute_values JSONB NOT NULL DEFAULT '{}',
                price_modifier BIGINT NOT NULL DEFAULT 0,
                stock_quantity INTEGER DEFAULT NULL,
                media_asset_id VARCHAR(36) DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                is_active BOOLEAN NOT NULL DEFAULT true,
                PRIMARY KEY (id),
                CONSTRAINT fk_variant_product FOREIGN KEY (product_id) REFERENCES cms_products (id) ON DELETE CASCADE,
                CONSTRAINT fk_variant_media_asset FOREIGN KEY (media_asset_id) REFERENCES cms_media_assets (id) ON DELETE SET NULL
            )
            SQL, $driver));

        $indexes->ensure(
            'cms_product_variants',
            'uq_variant_product_sku_suffix',
            ['product_id', 'sku_suffix'],
            unique: true,
        );

        $indexes->ensure(
            'cms_product_variants',
            'idx_variant_product_sort',
            ['product_id', 'sort_order'],
        );

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_product_attributes (
                id VARCHAR(36) NOT NULL,
                product_id VARCHAR(36) NOT NULL,
                attribute_key VARCHAR(50) NOT NULL,
                allowed_values JSONB NOT NULL DEFAULT '[]',
                translations JSONB DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_attribute_product FOREIGN KEY (product_id) REFERENCES cms_products (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $indexes->ensure(
            'cms_product_attributes',
            'uq_attribute_product_key',
            ['product_id', 'attribute_key'],
            unique: true,
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_product_attributes');
        $connection->execute('DROP TABLE IF EXISTS cms_product_variants');
    }
};
