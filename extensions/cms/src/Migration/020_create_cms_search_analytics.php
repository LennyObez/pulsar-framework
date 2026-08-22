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

        $indexes->ensure(
            'cms_search_analytics',
            'idx_search_analytics_tenant_hash_date',
            ['tenant_id', 'query_hash', 'searched_at'],
        );

        // An engine without partial indexes cannot filter on `result_count`, so it has to
        // carry it in the key instead.
        $indexes->ensure(
            'cms_search_analytics',
            'idx_search_analytics_zero_results',
            $connection->dialect()->supportsPartialIndexes()
                ? ['tenant_id', 'searched_at']
                : ['tenant_id', 'searched_at', 'result_count'],
            where: 'result_count = 0',
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_search_analytics');
    }
};
