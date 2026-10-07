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
            CREATE TABLE IF NOT EXISTS cms_content_events (
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
            SQL, $driver));

        $indexes->ensure(
            'cms_content_events',
            'uq_event_content_sequence',
            ['content_id', 'sequence'],
            unique: true,
        );

        $indexes->ensure(
            'cms_content_events',
            'idx_event_type_created',
            ['event_type', 'created_at'],
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_content_events');
    }
};
