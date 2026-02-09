<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        // Experiments table
        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_experiments (
                id VARCHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                content_id VARCHAR(36) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                traffic_percentage DOUBLE PRECISION NOT NULL DEFAULT 1.0,
                start_at TIMESTAMPTZ DEFAULT NULL,
                end_at TIMESTAMPTZ DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT chk_experiment_status CHECK (status IN ('draft', 'running', 'completed', 'cancelled')),
                CONSTRAINT chk_experiment_traffic CHECK (traffic_percentage >= 0.0 AND traffic_percentage <= 1.0)
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_experiments_content_id ON cms_experiments (content_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_experiments_status ON cms_experiments (status)
            SQL);

        // Experiment variants table
        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_experiment_variants (
                id VARCHAR(36) NOT NULL,
                experiment_id VARCHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                content_id VARCHAR(36) NOT NULL,
                weight INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_variant_experiment FOREIGN KEY (experiment_id) REFERENCES cms_experiments (id) ON DELETE CASCADE,
                CONSTRAINT chk_variant_weight CHECK (weight > 0)
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_experiment_variants_experiment_id ON cms_experiment_variants (experiment_id)
            SQL);

        // Conversion events table
        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_conversion_events (
                id VARCHAR(36) NOT NULL,
                experiment_id VARCHAR(36) NOT NULL,
                variant_id VARCHAR(36) NOT NULL,
                visitor_id VARCHAR(255) NOT NULL,
                type VARCHAR(50) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_conversion_experiment FOREIGN KEY (experiment_id) REFERENCES cms_experiments (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_conversion_events_experiment_id ON cms_conversion_events (experiment_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_conversion_events_visitor_experiment ON cms_conversion_events (visitor_id, experiment_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_conversion_events_variant_type ON cms_conversion_events (variant_id, type)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_conversion_events');
        $connection->execute('DROP TABLE IF EXISTS cms_experiment_variants');
        $connection->execute('DROP TABLE IF EXISTS cms_experiments');
    }
};
