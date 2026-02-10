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
            CREATE TABLE IF NOT EXISTS cms_search_analytics (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                query_text VARCHAR(500) NOT NULL,
                query_hash VARCHAR(64) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                result_count INTEGER NOT NULL DEFAULT 0,
                clicked_content_id VARCHAR(36) DEFAULT NULL,
                searched_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_search_analytics_content FOREIGN KEY (clicked_content_id) REFERENCES cms_contents (id) ON DELETE SET NULL
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_search_analytics_tenant_hash_date
                ON cms_search_analytics (tenant_id, query_hash, searched_at)
            SQL);

        // Partial index: supported by PostgreSQL and SQLite, fallback for MySQL
        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE INDEX idx_search_analytics_zero_results
                    ON cms_search_analytics (tenant_id, searched_at, result_count)
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_search_analytics_zero_results
                    ON cms_search_analytics (tenant_id, searched_at)
                    WHERE result_count = 0
                SQL);
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_search_analytics');
    }
};
