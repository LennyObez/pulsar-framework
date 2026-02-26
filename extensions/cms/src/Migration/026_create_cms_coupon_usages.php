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
            CREATE TABLE IF NOT EXISTS cms_coupon_usages (
                id VARCHAR(36) NOT NULL,
                promotion_id VARCHAR(36) NOT NULL,
                customer_id VARCHAR(36) NOT NULL,
                used_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT fk_coupon_usage_promotion FOREIGN KEY (promotion_id) REFERENCES cms_promotions (id) ON DELETE CASCADE,
                CONSTRAINT uq_coupon_usage UNIQUE (promotion_id, customer_id, used_at)
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_coupon_usage_customer
                ON cms_coupon_usages (promotion_id, customer_id)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_coupon_usages');
    }
};
