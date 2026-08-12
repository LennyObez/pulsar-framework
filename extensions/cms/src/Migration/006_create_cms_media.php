<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexColumn;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_media_assets (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                uploader_id VARCHAR(36) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                storage_path VARCHAR(500) NOT NULL,
                disk VARCHAR(50) NOT NULL DEFAULT 'local',
                mime_type VARCHAR(100) NOT NULL,
                file_size BIGINT NOT NULL,
                file_hash VARCHAR(128) NOT NULL,
                width INTEGER DEFAULT NULL,
                height INTEGER DEFAULT NULL,
                exif_data JSONB DEFAULT NULL,
                alt_text_default VARCHAR(500) DEFAULT NULL,
                visibility VARCHAR(20) NOT NULL DEFAULT 'public',
                data_classification VARCHAR(20) NOT NULL DEFAULT 'public',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMPTZ DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_media_visibility CHECK (visibility IN ('public', 'private')),
                CONSTRAINT chk_media_file_size_positive CHECK (file_size > 0),
                CONSTRAINT chk_media_data_classification CHECK (data_classification IN ('public', 'internal', 'confidential', 'pii'))
            )
            SQL, $driver));

        $indexes->ensure('cms_media_assets', 'idx_media_file_hash', ['file_hash']);

        // A tenant's library reads newest first.
        $indexes->ensure('cms_media_assets', 'idx_media_tenant_created', [
            'tenant_id',
            IndexColumn::desc('created_at'),
        ]);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_media_asset_translations (
                media_asset_id VARCHAR(36) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                alt_text VARCHAR(500) DEFAULT NULL,
                caption TEXT DEFAULT NULL,
                title VARCHAR(300) DEFAULT NULL,
                PRIMARY KEY (media_asset_id, locale),
                CONSTRAINT fk_media_asset_translation FOREIGN KEY (media_asset_id) REFERENCES cms_media_assets (id) ON DELETE CASCADE
            )
            SQL);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_media_derivatives (
                id VARCHAR(36) NOT NULL,
                media_asset_id VARCHAR(36) NOT NULL,
                variant VARCHAR(50) NOT NULL,
                format VARCHAR(10) NOT NULL,
                storage_path VARCHAR(500) NOT NULL,
                file_size BIGINT NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                file_hash VARCHAR(128) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_derivative_media_asset FOREIGN KEY (media_asset_id) REFERENCES cms_media_assets (id) ON DELETE CASCADE,
                CONSTRAINT uq_derivative_variant_format UNIQUE (media_asset_id, variant, format)
            )
            SQL, $driver));
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_media_derivatives');
        $connection->execute('DROP TABLE IF EXISTS cms_media_asset_translations');
        $connection->execute('DROP TABLE IF EXISTS cms_media_assets');
    }
};
