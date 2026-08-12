<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => $this->upSqlite($connection),
            Driver::MySQL => $this->upMysql($connection),
            Driver::PostgreSQL => $this->upPostgresql($connection),
        };

        $this->ensureIndexes($connection);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_newsletter_sends');
        $connection->execute('DROP TABLE IF EXISTS cms_newsletter_campaigns');
        $connection->execute('DROP TABLE IF EXISTS cms_newsletter_subscribers');
    }

    private function upSqlite(ConnectionInterface $connection): void
    {
        // Subscribers table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_newsletter_subscribers (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                user_id VARCHAR(36),
                locale VARCHAR(10) NOT NULL DEFAULT 'en',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                confirm_token_hash VARCHAR(128),
                confirmed_at TEXT,
                unsubscribed_at TEXT,
                ip_address_hash VARCHAR(128) NOT NULL,
                source VARCHAR(50) NOT NULL DEFAULT 'form',
                tenant_id VARCHAR(36),
                created_at TEXT NOT NULL
            )
            SQL);

        // Campaigns table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_newsletter_campaigns (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36),
                subject VARCHAR(255) NOT NULL,
                body_html TEXT NOT NULL,
                body_text TEXT,
                locale VARCHAR(10) NOT NULL DEFAULT 'en',
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                scheduled_at TEXT,
                sent_at TEXT,
                recipient_count INTEGER NOT NULL DEFAULT 0,
                opened_count INTEGER NOT NULL DEFAULT 0,
                clicked_count INTEGER NOT NULL DEFAULT 0,
                bounced_count INTEGER NOT NULL DEFAULT 0,
                created_by VARCHAR(36),
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);

        // Sends table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_newsletter_sends (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                campaign_id VARCHAR(36) NOT NULL REFERENCES cms_newsletter_campaigns(id) ON DELETE CASCADE,
                subscriber_id VARCHAR(36) NOT NULL REFERENCES cms_newsletter_subscribers(id) ON DELETE CASCADE,
                status VARCHAR(20) NOT NULL DEFAULT 'queued',
                sent_at TEXT,
                opened_at TEXT,
                clicked_at TEXT,
                bounce_reason TEXT
            )
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        // Subscribers table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_newsletter_subscribers (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                user_id VARCHAR(36),
                locale VARCHAR(10) NOT NULL DEFAULT 'en',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                confirm_token_hash VARCHAR(128),
                confirmed_at TIMESTAMP NULL,
                unsubscribed_at TIMESTAMP NULL,
                ip_address_hash VARCHAR(128) NOT NULL,
                source VARCHAR(50) NOT NULL DEFAULT 'form',
                tenant_id VARCHAR(36),
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_newsletter_subscribers_user
                    FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Campaigns table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_newsletter_campaigns (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36),
                subject VARCHAR(255) NOT NULL,
                body_html TEXT NOT NULL,
                body_text TEXT,
                locale VARCHAR(10) NOT NULL DEFAULT 'en',
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                scheduled_at TIMESTAMP NULL,
                sent_at TIMESTAMP NULL,
                recipient_count INT NOT NULL DEFAULT 0,
                opened_count INT NOT NULL DEFAULT 0,
                clicked_count INT NOT NULL DEFAULT 0,
                bounced_count INT NOT NULL DEFAULT 0,
                created_by VARCHAR(36),
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_newsletter_campaigns_creator
                    FOREIGN KEY (created_by) REFERENCES auth_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Sends table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_newsletter_sends (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                campaign_id VARCHAR(36) NOT NULL,
                subscriber_id VARCHAR(36) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'queued',
                sent_at TIMESTAMP NULL,
                opened_at TIMESTAMP NULL,
                clicked_at TIMESTAMP NULL,
                bounce_reason TEXT,
                CONSTRAINT fk_newsletter_sends_campaign
                    FOREIGN KEY (campaign_id) REFERENCES cms_newsletter_campaigns(id) ON DELETE CASCADE,
                CONSTRAINT fk_newsletter_sends_subscriber
                    FOREIGN KEY (subscriber_id) REFERENCES cms_newsletter_subscribers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        // Subscribers table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_newsletter_subscribers (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                user_id VARCHAR(36) REFERENCES auth_users(id) ON DELETE SET NULL,
                locale VARCHAR(10) NOT NULL DEFAULT 'en',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                confirm_token_hash VARCHAR(128),
                confirmed_at TIMESTAMPTZ,
                unsubscribed_at TIMESTAMPTZ,
                ip_address_hash VARCHAR(128) NOT NULL,
                source VARCHAR(50) NOT NULL DEFAULT 'form',
                tenant_id VARCHAR(36),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);

        // Campaigns table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_newsletter_campaigns (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36),
                subject VARCHAR(255) NOT NULL,
                body_html TEXT NOT NULL,
                body_text TEXT,
                locale VARCHAR(10) NOT NULL DEFAULT 'en',
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                scheduled_at TIMESTAMPTZ,
                sent_at TIMESTAMPTZ,
                recipient_count INT NOT NULL DEFAULT 0,
                opened_count INT NOT NULL DEFAULT 0,
                clicked_count INT NOT NULL DEFAULT 0,
                bounced_count INT NOT NULL DEFAULT 0,
                created_by VARCHAR(36) REFERENCES auth_users(id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);

        // Sends table
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_newsletter_sends (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                campaign_id VARCHAR(36) NOT NULL REFERENCES cms_newsletter_campaigns(id) ON DELETE CASCADE,
                subscriber_id VARCHAR(36) NOT NULL REFERENCES cms_newsletter_subscribers(id) ON DELETE CASCADE,
                status VARCHAR(20) NOT NULL DEFAULT 'queued',
                sent_at TIMESTAMPTZ,
                opened_at TIMESTAMPTZ,
                clicked_at TIMESTAMPTZ,
                bounce_reason TEXT
            )
            SQL);
    }

    /**
     * The eight indexes of the three tables, stated once rather than once per engine.
     *
     * They used to be written three times: twice as `CREATE INDEX IF NOT EXISTS`, which MySQL
     * rejects as a syntax error rather than ignoring, and a third time inline in the MySQL
     * `CREATE TABLE` to get around that. Nothing compared the copies.
     */
    private function ensureIndexes(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        $indexes->ensure(
            'cms_newsletter_subscribers',
            'idx_newsletter_subscribers_email_tenant',
            ['email', 'tenant_id'],
            unique: true,
        );
        $indexes->ensure('cms_newsletter_subscribers', 'idx_newsletter_subscribers_status', ['status']);
        $indexes->ensure('cms_newsletter_subscribers', 'idx_newsletter_subscribers_tenant', ['tenant_id']);

        $indexes->ensure('cms_newsletter_campaigns', 'idx_newsletter_campaigns_tenant_status', ['tenant_id', 'status']);
        $indexes->ensure('cms_newsletter_campaigns', 'idx_newsletter_campaigns_status', ['status']);

        $indexes->ensure(
            'cms_newsletter_sends',
            'idx_newsletter_sends_campaign_subscriber',
            ['campaign_id', 'subscriber_id'],
            unique: true,
        );
        $indexes->ensure('cms_newsletter_sends', 'idx_newsletter_sends_campaign_status', ['campaign_id', 'status']);
        $indexes->ensure('cms_newsletter_sends', 'idx_newsletter_sends_subscriber', ['subscriber_id']);
    }
};
