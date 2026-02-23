<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_content_snapshots (
                id VARCHAR(36) NOT NULL,
                content_id VARCHAR(36) NOT NULL,
                snapshot_number INTEGER NOT NULL,
                translations_json JSONB NOT NULL,
                blocks_json JSONB NOT NULL DEFAULT '[]',
                taxonomy_term_ids JSONB NOT NULL DEFAULT '[]',
                evidence_hash VARCHAR(128) NOT NULL,
                reason VARCHAR(500) NOT NULL,
                created_by VARCHAR(36) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_snapshot_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_snapshot_content_number
                ON cms_content_snapshots (content_id, snapshot_number)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_content_snapshots');
    }
};
