<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_contents (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                content_type VARCHAR(100) NOT NULL,
                author_id VARCHAR(36) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                scheduled_publish_at TIMESTAMPTZ DEFAULT NULL,
                scheduled_unpublish_at TIMESTAMPTZ DEFAULT NULL,
                published_at TIMESTAMPTZ DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMPTZ DEFAULT NULL,
                template VARCHAR(255) DEFAULT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                comment_policy VARCHAR(20) NOT NULL DEFAULT 'inherit',
                data_classification VARCHAR(20) NOT NULL DEFAULT 'public',
                PRIMARY KEY (id),
                CONSTRAINT fk_content_parent FOREIGN KEY (parent_id) REFERENCES cms_contents (id) ON DELETE SET NULL,
                CONSTRAINT chk_content_status CHECK (status IN ('draft', 'in_review', 'approved', 'scheduled', 'published', 'archived')),
                CONSTRAINT chk_content_scheduled_requires_date CHECK (status != 'scheduled' OR scheduled_publish_at IS NOT NULL),
                CONSTRAINT chk_content_scheduled_date_future CHECK (scheduled_publish_at IS NULL OR scheduled_publish_at > created_at),
                CONSTRAINT chk_content_published_at_set CHECK (status != 'published' OR published_at IS NOT NULL),
                CONSTRAINT chk_content_comment_policy CHECK (comment_policy IN ('open', 'moderated', 'closed', 'inherit')),
                CONSTRAINT chk_content_data_classification CHECK (data_classification IN ('public', 'internal', 'confidential', 'pii')),
                CONSTRAINT chk_content_no_self_parent CHECK (parent_id IS NULL OR parent_id != id)
            )
            SQL, $driver));

        $indexes->ensure('cms_contents', 'idx_content_status_tenant', ['status', 'tenant_id']);

        $indexes->ensure(
            'cms_contents',
            'idx_content_type_status_tenant',
            ['content_type', 'status', 'tenant_id'],
        );

        $indexes->ensure('cms_contents', 'idx_content_parent_sort', ['parent_id', 'sort_order']);

        // An engine without partial indexes cannot filter on `status`, so it has to carry
        // it in the key instead.
        $indexes->ensure(
            'cms_contents',
            'idx_content_scheduled_publish',
            $connection->dialect()->supportsPartialIndexes()
                ? ['scheduled_publish_at']
                : ['scheduled_publish_at', 'status'],
            where: "status = 'scheduled'",
        );

        // cms_content_translations: regex CHECK constraints are PostgreSQL-only,
        // TSVECTOR and GIN index are PostgreSQL-only
        if ($driver === Driver::PostgreSQL) {
            $connection->execute(CmsDdl::adapt(<<<'SQL'
                CREATE TABLE IF NOT EXISTS cms_content_translations (
                    id VARCHAR(36) NOT NULL,
                    content_id VARCHAR(36) NOT NULL,
                    locale VARCHAR(5) NOT NULL,
                    tenant_id VARCHAR(36) DEFAULT NULL,
                    tenant_key VARCHAR(36) NOT NULL GENERATED ALWAYS AS (
                        COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000')
                    ) STORED,
                    title VARCHAR(500) NOT NULL,
                    slug_segment VARCHAR(200) NOT NULL,
                    path VARCHAR(2000) NOT NULL,
                    body TEXT NOT NULL DEFAULT '',
                    excerpt TEXT DEFAULT NULL,
                    meta_title VARCHAR(70) DEFAULT NULL,
                    meta_description VARCHAR(170) DEFAULT NULL,
                    og_image_id VARCHAR(36) DEFAULT NULL,
                    robots VARCHAR(200) DEFAULT NULL,
                    structured_data_overrides JSONB DEFAULT NULL,
                    reading_time_minutes INTEGER DEFAULT NULL,
                    body_plaintext TEXT NOT NULL DEFAULT '',
                    headings_text TEXT NOT NULL DEFAULT '',
                    custom_fields_text TEXT NOT NULL DEFAULT '',
                    taxonomy_terms_text TEXT NOT NULL DEFAULT '',
                    search_vector TSVECTOR DEFAULT NULL,
                    PRIMARY KEY (id),
                    CONSTRAINT fk_translation_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE CASCADE,
                    CONSTRAINT chk_slug_segment_format CHECK (slug_segment ~ '^[a-z0-9]([a-z0-9-]*[a-z0-9])?$'),
                    CONSTRAINT chk_path_format CHECK (path ~ '^[a-z0-9]([a-z0-9/-]*[a-z0-9])?$')
                )
                SQL, $driver));
        } elseif ($driver === Driver::MySQL) {
            $connection->execute(CmsDdl::adapt(<<<'SQL'
                CREATE TABLE IF NOT EXISTS cms_content_translations (
                    id VARCHAR(36) NOT NULL,
                    content_id VARCHAR(36) NOT NULL,
                    locale VARCHAR(5) NOT NULL,
                    tenant_id VARCHAR(36) DEFAULT NULL,
                    tenant_key VARCHAR(36) NOT NULL GENERATED ALWAYS AS (
                        COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000')
                    ) STORED,
                    title VARCHAR(500) NOT NULL,
                    slug_segment VARCHAR(200) NOT NULL,
                    path VARCHAR(2000) NOT NULL,
                    body TEXT NOT NULL DEFAULT '',
                    excerpt TEXT DEFAULT NULL,
                    meta_title VARCHAR(70) DEFAULT NULL,
                    meta_description VARCHAR(170) DEFAULT NULL,
                    og_image_id VARCHAR(36) DEFAULT NULL,
                    robots VARCHAR(200) DEFAULT NULL,
                    structured_data_overrides JSONB DEFAULT NULL,
                    reading_time_minutes INTEGER DEFAULT NULL,
                    body_plaintext TEXT NOT NULL DEFAULT '',
                    headings_text TEXT NOT NULL DEFAULT '',
                    custom_fields_text TEXT NOT NULL DEFAULT '',
                    taxonomy_terms_text TEXT NOT NULL DEFAULT '',
                    search_vector TSVECTOR DEFAULT NULL,
                    PRIMARY KEY (id),
                    CONSTRAINT fk_translation_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE CASCADE,
                    CONSTRAINT chk_slug_segment_format CHECK (slug_segment REGEXP '^[a-z0-9]([a-z0-9-]*[a-z0-9])?$'),
                    CONSTRAINT chk_path_format CHECK (path REGEXP '^[a-z0-9]([a-z0-9/-]*[a-z0-9])?$')
                )
                SQL, $driver));
        } else {
            // SQLite: omit regex CHECK constraints (no built-in regex support)
            $connection->execute(CmsDdl::adapt(<<<'SQL'
                CREATE TABLE IF NOT EXISTS cms_content_translations (
                    id VARCHAR(36) NOT NULL,
                    content_id VARCHAR(36) NOT NULL,
                    locale VARCHAR(5) NOT NULL,
                    tenant_id VARCHAR(36) DEFAULT NULL,
                    tenant_key VARCHAR(36) NOT NULL GENERATED ALWAYS AS (
                        COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000')
                    ) STORED,
                    title VARCHAR(500) NOT NULL,
                    slug_segment VARCHAR(200) NOT NULL,
                    path VARCHAR(2000) NOT NULL,
                    body TEXT NOT NULL DEFAULT '',
                    excerpt TEXT DEFAULT NULL,
                    meta_title VARCHAR(70) DEFAULT NULL,
                    meta_description VARCHAR(170) DEFAULT NULL,
                    og_image_id VARCHAR(36) DEFAULT NULL,
                    robots VARCHAR(200) DEFAULT NULL,
                    structured_data_overrides JSONB DEFAULT NULL,
                    reading_time_minutes INTEGER DEFAULT NULL,
                    body_plaintext TEXT NOT NULL DEFAULT '',
                    headings_text TEXT NOT NULL DEFAULT '',
                    custom_fields_text TEXT NOT NULL DEFAULT '',
                    taxonomy_terms_text TEXT NOT NULL DEFAULT '',
                    search_vector TSVECTOR DEFAULT NULL,
                    PRIMARY KEY (id),
                    CONSTRAINT fk_translation_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE CASCADE
                )
                SQL, $driver));
        }

        $indexes->ensure(
            'cms_content_translations',
            'uq_translation_locale_tenant_path',
            ['locale', 'tenant_key', 'path'],
            unique: true,
        );

        $indexes->ensure(
            'cms_content_translations',
            'uq_translation_content_locale',
            ['content_id', 'locale'],
            unique: true,
        );

        // GIN index: PostgreSQL only
        if ($driver === Driver::PostgreSQL) {
            $connection->execute(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_translation_search_vector
                    ON cms_content_translations USING GIN (search_vector)
                SQL);
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_content_translations');
        $connection->execute('DROP TABLE IF EXISTS cms_contents');
    }
};
