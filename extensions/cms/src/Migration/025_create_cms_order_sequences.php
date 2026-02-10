<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_order_sequences (
                tenant_id VARCHAR(36) NOT NULL DEFAULT '__global__',
                last_number INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (tenant_id)
            )
            SQL);

        // Seed sequences from existing order counts so new numbers continue
        // where the old COUNT(*)-based generation left off.
        $connection->execute(<<<'SQL'
            INSERT INTO cms_order_sequences (tenant_id, last_number)
            SELECT COALESCE(tenant_id, '__global__'), COUNT(*)
            FROM cms_orders
            GROUP BY COALESCE(tenant_id, '__global__')
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_order_sequences');
    }
};
