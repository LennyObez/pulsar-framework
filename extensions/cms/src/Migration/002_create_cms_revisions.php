<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE cms_content_revisions (
                id VARCHAR(36) NOT NULL,
                content_id VARCHAR(36) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                revision_number INTEGER NOT NULL,
                title VARCHAR(500) NOT NULL,
                slug VARCHAR(500) NOT NULL,
                body TEXT NOT NULL,
                excerpt TEXT DEFAULT NULL,
                meta_title VARCHAR(70) DEFAULT NULL,
                meta_description VARCHAR(170) DEFAULT NULL,
                author_id VARCHAR(36) NOT NULL,
                reason VARCHAR(500) DEFAULT NULL,
                evidence_hash VARCHAR(128) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_revision_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE CASCADE
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX idx_revision_content_locale_number
                ON cms_content_revisions (content_id, locale, revision_number)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_content_revisions');
    }
};
