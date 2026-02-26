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
            CREATE TABLE IF NOT EXISTS cms_taxonomies (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                slug VARCHAR(100) NOT NULL,
                hierarchical BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id)
            )
            SQL, $driver));

        // Expression index with COALESCE: PostgreSQL + SQLite support it;
        // MySQL fallback uses a standard composite index
        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX uq_taxonomy_slug_tenant
                    ON cms_taxonomies (tenant_id, slug)
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uq_taxonomy_slug_tenant
                    ON cms_taxonomies (COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'), slug)
                SQL);
        }

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_taxonomy_translations (
                taxonomy_id VARCHAR(36) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                name VARCHAR(200) NOT NULL,
                description TEXT DEFAULT NULL,
                PRIMARY KEY (taxonomy_id, locale),
                CONSTRAINT fk_taxonomy_translation FOREIGN KEY (taxonomy_id) REFERENCES cms_taxonomies (id) ON DELETE CASCADE
            )
            SQL);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_taxonomy_terms (
                id VARCHAR(36) NOT NULL,
                taxonomy_id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_term_taxonomy FOREIGN KEY (taxonomy_id) REFERENCES cms_taxonomies (id) ON DELETE CASCADE,
                CONSTRAINT fk_term_parent FOREIGN KEY (parent_id) REFERENCES cms_taxonomy_terms (id) ON DELETE SET NULL
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_taxonomy_term_translations (
                term_id VARCHAR(36) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                name VARCHAR(200) NOT NULL,
                slug VARCHAR(200) NOT NULL,
                description TEXT DEFAULT NULL,
                tenant_key VARCHAR(36) NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000',
                taxonomy_id VARCHAR(36) NOT NULL,
                PRIMARY KEY (term_id, locale),
                CONSTRAINT fk_term_translation FOREIGN KEY (term_id) REFERENCES cms_taxonomy_terms (id) ON DELETE CASCADE,
                CONSTRAINT fk_term_translation_taxonomy FOREIGN KEY (taxonomy_id) REFERENCES cms_taxonomies (id) ON DELETE CASCADE
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_term_slug_taxonomy_locale_tenant
                ON cms_taxonomy_term_translations (taxonomy_id, locale, slug, tenant_key)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_content_taxonomy_terms (
                content_id VARCHAR(36) NOT NULL,
                term_id VARCHAR(36) NOT NULL,
                PRIMARY KEY (content_id, term_id),
                CONSTRAINT fk_content_term_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_term_term FOREIGN KEY (term_id) REFERENCES cms_taxonomy_terms (id) ON DELETE CASCADE
            )
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_content_taxonomy_terms');
        $connection->execute('DROP TABLE IF EXISTS cms_taxonomy_term_translations');
        $connection->execute('DROP TABLE IF EXISTS cms_taxonomy_terms');
        $connection->execute('DROP TABLE IF EXISTS cms_taxonomy_translations');
        $connection->execute('DROP TABLE IF EXISTS cms_taxonomies');
    }
};
