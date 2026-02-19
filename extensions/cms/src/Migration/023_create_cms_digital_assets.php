<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_digital_assets (
                id VARCHAR(36) NOT NULL,
                product_id VARCHAR(36) NOT NULL,
                file_storage_path VARCHAR(500) NOT NULL,
                file_hash VARCHAR(128) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_size BIGINT NOT NULL,
                max_downloads INTEGER NOT NULL DEFAULT 5,
                PRIMARY KEY (id),
                CONSTRAINT fk_digital_asset_product FOREIGN KEY (product_id) REFERENCES cms_products (id) ON DELETE CASCADE,
                CONSTRAINT chk_digital_asset_file_size_positive CHECK (file_size > 0),
                CONSTRAINT chk_digital_asset_max_downloads_positive CHECK (max_downloads > 0)
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_digital_asset_product ON cms_digital_assets (product_id)
            SQL);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_digital_downloads (
                id VARCHAR(36) NOT NULL,
                order_item_id VARCHAR(36) NOT NULL,
                digital_asset_id VARCHAR(36) NOT NULL,
                download_token VARCHAR(128) NOT NULL,
                downloads_remaining INTEGER NOT NULL,
                expires_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_download_order_item FOREIGN KEY (order_item_id) REFERENCES cms_order_items (id) ON DELETE CASCADE,
                CONSTRAINT fk_download_digital_asset FOREIGN KEY (digital_asset_id) REFERENCES cms_digital_assets (id) ON DELETE CASCADE,
                CONSTRAINT chk_download_remaining_non_negative CHECK (downloads_remaining >= 0)
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_download_token
                ON cms_digital_downloads (download_token)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_download_order_item ON cms_digital_downloads (order_item_id)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_digital_downloads');
        $connection->execute('DROP TABLE IF EXISTS cms_digital_assets');
    }
};
