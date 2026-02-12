<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => $this->upSqlite($connection),
            Driver::MySQL => $this->upMysql($connection),
            Driver::PostgreSQL => $this->upPostgresql($connection),
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_doc_feedback');
        $connection->execute('DROP TABLE IF EXISTS cms_doc_section_translations');
        $connection->execute('DROP TABLE IF EXISTS cms_doc_sections');
        $connection->execute('DROP TABLE IF EXISTS cms_doc_versions');
    }

    private function upSqlite(ConnectionInterface $connection): void
    {
        // Doc versions table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_doc_versions (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                version_slug VARCHAR(50) NOT NULL,
                version_label VARCHAR(100) NOT NULL,
                framework_version VARCHAR(50),
                is_default BOOLEAN NOT NULL DEFAULT 0,
                is_archived BOOLEAN NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_doc_versions_slug
                ON cms_doc_versions (version_slug)
            SQL);

        // Doc sections table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_doc_sections (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                version_id VARCHAR(36) NOT NULL REFERENCES cms_doc_versions(id) ON DELETE CASCADE,
                slug VARCHAR(100) NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_doc_sections_version_slug
                ON cms_doc_sections (version_id, slug)
            SQL);

        // Doc section translations table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_doc_section_translations (
                section_id VARCHAR(36) NOT NULL REFERENCES cms_doc_sections(id) ON DELETE CASCADE,
                locale VARCHAR(10) NOT NULL,
                label VARCHAR(255) NOT NULL,
                PRIMARY KEY (section_id, locale)
            )
            SQL);

        // Doc feedback table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_doc_feedback (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                doc_page_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36),
                is_helpful BOOLEAN NOT NULL,
                comment TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_doc_feedback_doc_page
                ON cms_doc_feedback (doc_page_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_doc_feedback_user
                ON cms_doc_feedback (user_id)
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        // Doc versions table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_doc_versions (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                version_slug VARCHAR(50) NOT NULL,
                version_label VARCHAR(100) NOT NULL,
                framework_version VARCHAR(50),
                is_default BOOLEAN NOT NULL DEFAULT FALSE,
                is_archived BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE INDEX idx_doc_versions_slug (version_slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Doc sections table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_doc_sections (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                version_id VARCHAR(36) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                UNIQUE INDEX idx_doc_sections_version_slug (version_id, slug),
                CONSTRAINT fk_doc_sections_version
                    FOREIGN KEY (version_id) REFERENCES cms_doc_versions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Doc section translations table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_doc_section_translations (
                section_id VARCHAR(36) NOT NULL,
                locale VARCHAR(10) NOT NULL,
                label VARCHAR(255) NOT NULL,
                PRIMARY KEY (section_id, locale),
                CONSTRAINT fk_doc_section_translations_section
                    FOREIGN KEY (section_id) REFERENCES cms_doc_sections(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Doc feedback table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_doc_feedback (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                doc_page_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36),
                is_helpful BOOLEAN NOT NULL,
                comment TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_doc_feedback_doc_page (doc_page_id),
                INDEX idx_doc_feedback_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        // Doc versions table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_doc_versions (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                version_slug VARCHAR(50) NOT NULL,
                version_label VARCHAR(100) NOT NULL,
                framework_version VARCHAR(50),
                is_default BOOLEAN NOT NULL DEFAULT FALSE,
                is_archived BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_doc_versions_slug
                ON cms_doc_versions (version_slug)
            SQL);

        // Doc sections table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_doc_sections (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                version_id VARCHAR(36) NOT NULL REFERENCES cms_doc_versions(id) ON DELETE CASCADE,
                slug VARCHAR(100) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_doc_sections_version_slug
                ON cms_doc_sections (version_id, slug)
            SQL);

        // Doc section translations table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_doc_section_translations (
                section_id VARCHAR(36) NOT NULL REFERENCES cms_doc_sections(id) ON DELETE CASCADE,
                locale VARCHAR(10) NOT NULL,
                label VARCHAR(255) NOT NULL,
                PRIMARY KEY (section_id, locale)
            )
            SQL);

        // Doc feedback table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_doc_feedback (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                doc_page_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36),
                is_helpful BOOLEAN NOT NULL,
                comment TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_doc_feedback_doc_page
                ON cms_doc_feedback (doc_page_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_doc_feedback_user
                ON cms_doc_feedback (user_id)
            SQL);
    }
};
