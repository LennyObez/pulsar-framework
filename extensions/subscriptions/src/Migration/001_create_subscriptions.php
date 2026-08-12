<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;

/**
 * The eight indexes below were written once per engine — inline in MySQL's `CREATE TABLE`,
 * as standalone statements on the other two — and nothing but the spelling ever differed.
 * Stated once they cannot drift apart again. The tables stay split because their timestamp
 * types genuinely do differ.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => $this->upSqlite($connection),
            Driver::MySQL => $this->upMysql($connection),
            Driver::PostgreSQL => $this->upPostgresql($connection),
        };

        $indexes->ensure('subscriptions', 'idx_subscriptions_token_hash', ['purchase_token_hash'], unique: true);

        $indexes->ensure('subscriptions', 'idx_subscriptions_user_id', ['user_id']);

        $indexes->ensure('subscriptions', 'idx_subscriptions_original_txn', ['original_transaction_id']);

        $indexes->ensure('subscriptions', 'idx_subscriptions_status', ['status']);

        $indexes->ensure('subscriptions', 'idx_subscriptions_expires', ['expires_at']);

        $indexes->ensure('webhook_events', 'idx_webhook_events_type', ['event_type']);

        $indexes->ensure('webhook_events', 'idx_webhook_events_store', ['store']);

        $indexes->ensure('webhook_events', 'idx_webhook_events_created', ['created_at']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS webhook_events');
        $connection->execute('DROP TABLE IF EXISTS subscriptions');
    }

    private function upSqlite(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS subscriptions (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                user_id VARCHAR(64) NOT NULL,
                store VARCHAR(10) NOT NULL,
                product_id VARCHAR(255) NOT NULL,
                plan VARCHAR(100) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                purchase_token_hash VARCHAR(64) NOT NULL,
                raw_receipt_encrypted TEXT,
                original_transaction_id VARCHAR(255) NOT NULL,
                expires_at TEXT,
                grace_period_until TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS webhook_events (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                store VARCHAR(10) NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                payload_encrypted TEXT NOT NULL,
                signature_verified INTEGER NOT NULL DEFAULT 0,
                processed_at TEXT,
                created_at TEXT NOT NULL
            )
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS subscriptions (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                user_id VARCHAR(64) NOT NULL,
                store VARCHAR(10) NOT NULL,
                product_id VARCHAR(255) NOT NULL,
                plan VARCHAR(100) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                purchase_token_hash VARCHAR(64) NOT NULL,
                raw_receipt_encrypted TEXT,
                original_transaction_id VARCHAR(255) NOT NULL,
                expires_at DATETIME NULL,
                grace_period_until DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS webhook_events (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                store VARCHAR(10) NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                payload_encrypted TEXT NOT NULL,
                signature_verified TINYINT NOT NULL DEFAULT 0,
                processed_at DATETIME NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS subscriptions (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                user_id VARCHAR(64) NOT NULL,
                store VARCHAR(10) NOT NULL,
                product_id VARCHAR(255) NOT NULL,
                plan VARCHAR(100) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                purchase_token_hash VARCHAR(64) NOT NULL,
                raw_receipt_encrypted TEXT,
                original_transaction_id VARCHAR(255) NOT NULL,
                expires_at TIMESTAMPTZ,
                grace_period_until TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS webhook_events (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                store VARCHAR(10) NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                payload_encrypted TEXT NOT NULL,
                signature_verified BOOLEAN NOT NULL DEFAULT FALSE,
                processed_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);
    }
};
