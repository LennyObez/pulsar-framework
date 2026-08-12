<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_promotions (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                name VARCHAR(200) NOT NULL,
                type VARCHAR(30) NOT NULL,
                value BIGINT NOT NULL,
                min_order_amount BIGINT DEFAULT NULL,
                max_uses INTEGER DEFAULT NULL,
                max_uses_per_customer INTEGER DEFAULT NULL,
                current_uses INTEGER NOT NULL DEFAULT 0,
                applicable_product_ids JSONB DEFAULT NULL,
                applicable_category_ids JSONB DEFAULT NULL,
                starts_at TIMESTAMPTZ NOT NULL,
                expires_at TIMESTAMPTZ DEFAULT NULL,
                is_active BOOLEAN NOT NULL DEFAULT true,
                PRIMARY KEY (id),
                CONSTRAINT chk_promotion_type CHECK (type IN (
                    'percentage_off', 'fixed_amount_off', 'free_shipping', 'buy_x_get_y'
                )),
                CONSTRAINT chk_promotion_value_positive CHECK (value > 0),
                CONSTRAINT chk_promotion_current_uses_non_negative CHECK (current_uses >= 0),
                CONSTRAINT chk_promotion_uses_within_max CHECK (
                    max_uses IS NULL OR current_uses <= max_uses
                ),
                CONSTRAINT chk_promotion_expires_after_starts CHECK (
                    expires_at IS NULL OR expires_at > starts_at
                )
            )
            SQL, $driver));

        // `is_active` stays in the key rather than only in the predicate, so an engine
        // without partial indexes still narrows to the live promotions on its own.
        $indexes->ensure(
            'cms_promotions',
            'idx_promotion_tenant_active',
            ['tenant_id', 'is_active'],
            where: 'is_active = true',
        );

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_coupons (
                id VARCHAR(36) NOT NULL,
                promotion_id VARCHAR(36) NOT NULL,
                code VARCHAR(50) NOT NULL,
                is_single_use BOOLEAN NOT NULL DEFAULT false,
                used_at TIMESTAMPTZ DEFAULT NULL,
                used_by VARCHAR(36) DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_coupon_promotion FOREIGN KEY (promotion_id) REFERENCES cms_promotions (id) ON DELETE CASCADE
            )
            SQL, $driver));

        // Expression index (LOWER): PostgreSQL + SQLite support it; MySQL fallback
        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX uq_coupon_code
                    ON cms_coupons (code)
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uq_coupon_code
                    ON cms_coupons (LOWER(code))
                SQL);
        }

        $indexes->ensure('cms_coupons', 'idx_coupon_promotion', ['promotion_id']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_coupons');
        $connection->execute('DROP TABLE IF EXISTS cms_promotions');
    }
};
