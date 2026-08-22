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
            CREATE TABLE IF NOT EXISTS cms_orders (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                tenant_key VARCHAR(36) NOT NULL GENERATED ALWAYS AS (
                    COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000')
                ) STORED,
                order_number VARCHAR(50) NOT NULL,
                customer_id VARCHAR(36) DEFAULT NULL,
                customer_email VARCHAR(320) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'cart',
                subtotal BIGINT NOT NULL DEFAULT 0,
                tax_amount BIGINT NOT NULL DEFAULT 0,
                discount_amount BIGINT NOT NULL DEFAULT 0,
                total BIGINT NOT NULL DEFAULT 0,
                amount_refunded BIGINT NOT NULL DEFAULT 0,
                currency VARCHAR(3) NOT NULL,
                payment_intent_id VARCHAR(255) DEFAULT NULL,
                payment_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                billing_address JSONB DEFAULT NULL,
                shipping_address JSONB DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                data_classification VARCHAR(20) NOT NULL DEFAULT 'pii',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT chk_order_status CHECK (status IN (
                    'cart', 'pending_payment', 'confirmed', 'fulfilled',
                    'refunded', 'failed', 'cancelled'
                )),
                CONSTRAINT chk_order_payment_status CHECK (payment_status IN (
                    'pending', 'paid', 'failed', 'refunded', 'partially_refunded'
                )),
                CONSTRAINT chk_order_total_non_negative CHECK (total >= 0),
                CONSTRAINT chk_order_subtotal_non_negative CHECK (subtotal >= 0),
                CONSTRAINT chk_order_tax_non_negative CHECK (tax_amount >= 0),
                CONSTRAINT chk_order_discount_non_negative CHECK (discount_amount >= 0),
                CONSTRAINT chk_order_refund_non_negative CHECK (amount_refunded >= 0),
                CONSTRAINT chk_order_refund_ceiling CHECK (amount_refunded <= total)
            )
            SQL, $driver));

        $indexes->ensure(
            'cms_orders',
            'uq_order_tenant_number',
            ['tenant_key', 'order_number'],
            unique: true,
        );

        $indexes->ensure(
            'cms_orders',
            'idx_order_customer',
            ['customer_id'],
            where: 'customer_id IS NOT NULL',
        );

        $indexes->ensure('cms_orders', 'idx_order_status_tenant', ['status', 'tenant_id']);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_order_items (
                id VARCHAR(36) NOT NULL,
                order_id VARCHAR(36) NOT NULL,
                product_id VARCHAR(36) NOT NULL,
                variant_id VARCHAR(36) DEFAULT NULL,
                quantity INTEGER NOT NULL,
                unit_price BIGINT NOT NULL,
                total_price BIGINT NOT NULL,
                tax_amount BIGINT NOT NULL DEFAULT 0,
                discount_amount BIGINT NOT NULL DEFAULT 0,
                product_snapshot JSONB NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES cms_orders (id) ON DELETE CASCADE,
                CONSTRAINT chk_order_item_quantity_positive CHECK (quantity > 0),
                CONSTRAINT chk_order_item_unit_price_non_negative CHECK (unit_price >= 0),
                CONSTRAINT chk_order_item_total_non_negative CHECK (total_price >= 0)
            )
            SQL, $driver));

        $indexes->ensure('cms_order_items', 'idx_order_item_order', ['order_id']);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_invoices (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                tenant_key VARCHAR(36) NOT NULL GENERATED ALWAYS AS (
                    COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000')
                ) STORED,
                order_id VARCHAR(36) NOT NULL,
                invoice_number VARCHAR(50) NOT NULL,
                issued_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                due_at TIMESTAMPTZ DEFAULT NULL,
                pdf_storage_path VARCHAR(500) DEFAULT NULL,
                pdf_hash VARCHAR(128) DEFAULT NULL,
                evidence_hash VARCHAR(128) NOT NULL,
                data_classification VARCHAR(20) NOT NULL DEFAULT 'pii',
                PRIMARY KEY (id),
                CONSTRAINT fk_invoice_order FOREIGN KEY (order_id) REFERENCES cms_orders (id) ON DELETE RESTRICT
            )
            SQL, $driver));

        $indexes->ensure(
            'cms_invoices',
            'uq_invoice_tenant_number',
            ['tenant_key', 'invoice_number'],
            unique: true,
        );

        $indexes->ensure('cms_invoices', 'idx_invoice_order', ['order_id']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_invoices');
        $connection->execute('DROP TABLE IF EXISTS cms_order_items');
        $connection->execute('DROP TABLE IF EXISTS cms_orders');
    }
};
