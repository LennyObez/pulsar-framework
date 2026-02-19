<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE cms_content_events (
                id VARCHAR(36) NOT NULL,
                content_id VARCHAR(36) NOT NULL,
                sequence BIGINT NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                payload JSONB NOT NULL DEFAULT '{}',
                actor_id VARCHAR(36) NOT NULL,
                reason VARCHAR(500) DEFAULT NULL,
                evidence_hash VARCHAR(128) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_event_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE CASCADE,
                CONSTRAINT chk_event_sequence_positive CHECK (sequence > 0)
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX uq_event_content_sequence
                ON cms_content_events (content_id, sequence)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX idx_event_type_created
                ON cms_content_events (event_type, created_at)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_content_events');
    }
};
