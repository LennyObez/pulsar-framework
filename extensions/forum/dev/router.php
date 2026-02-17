<?php

declare(strict_types=1);

/**
 * Router script for the Forum standalone development server (php -S).
 *
 * Usage: php -S host:port -t . extensions/forum/dev/router.php
 */

// Find project root by locating vendor/autoload.php
$dir = __DIR__;
while ($dir !== dirname($dir)) {
    if (file_exists($dir . '/vendor/autoload.php')) {
        break;
    }
    $dir = dirname($dir);
}

require $dir . '/vendor/autoload.php';

use Pulsar\Database\ConnectionInterface;
use Pulsar\Dev\DevServerBootstrap;
use Pulsar\Dev\DevServerConfig;
use Pulsar\Extension\Forum\Config\ForumConfig;

DevServerBootstrap::run($dir, new DevServerConfig(
    extensionName: 'forum',
    assetPrefixes: [
        '/forum/assets/' => [
            'extensions/forum/frontend/styles',
            'extensions/forum/frontend/dist',
        ],
        '/admin/assets/' => [
            'extensions/admin/frontend/styles',
            'extensions/admin/frontend/dist',
        ],
    ],
    templatePaths: [
        $dir . '/resources/views',
        $dir . '/extensions/forum/resources/views',
    ],
    containerBindings: [
        ForumConfig::class => ForumConfig::fromArray([
            'allow_guest_viewing' => true,
        ]),
    ],
    devIdentityRoles: ['admin', 'moderator'],
    migrationDirs: ['extensions/forum/src/Migration'],
    setupSql: [
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS password_resets (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL,
                token_hash VARCHAR(128) NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
            SQL,
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS sessions (
                id VARCHAR(128) NOT NULL PRIMARY KEY,
                user_id VARCHAR(36) DEFAULT NULL,
                payload TEXT NOT NULL DEFAULT '{}',
                last_activity INTEGER NOT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
            SQL,
    ],
    seeders: [
        static function (ConnectionInterface $db): void {
            // Seed dev admin user
            $devPasswordHash = password_hash('forum-dev-password', PASSWORD_BCRYPT);
            $db->execute(
                <<<'SQL'
                    INSERT OR IGNORE INTO auth_users (id, display_name, email, password_hash, roles, two_factor_status)
                    VALUES ('dev-admin', 'Dev Administrator', 'admin@forum.local', :hash, '["admin","moderator"]', 'disabled')
                    SQL,
                ['hash' => $devPasswordHash],
            );

            // Seed default categories
            $db->execute(<<<'SQL'
                INSERT OR IGNORE INTO forum_categories (id, slug, sort_order, is_locked, created_at, updated_at)
                VALUES
                    ('cat-general', 'general', 0, 0, datetime('now'), datetime('now')),
                    ('cat-help', 'help-support', 1, 0, datetime('now'), datetime('now')),
                    ('cat-showcase', 'showcase', 2, 0, datetime('now'), datetime('now')),
                    ('cat-off-topic', 'off-topic', 3, 0, datetime('now'), datetime('now'))
                SQL);

            // Seed category translations
            $db->execute(<<<'SQL'
                INSERT OR IGNORE INTO forum_category_translations (id, category_id, locale, name, description)
                VALUES
                    ('tr-gen', 'cat-general', 'en', 'General Discussion', 'Talk about anything related to the community'),
                    ('tr-help', 'cat-help', 'en', 'Help & Support', 'Get help with technical questions and issues'),
                    ('tr-show', 'cat-showcase', 'en', 'Showcase', 'Share your projects and achievements'),
                    ('tr-off', 'cat-off-topic', 'en', 'Off-Topic', 'Casual conversation and non-technical discussions')
                SQL);
        },
    ],
    criticalTables: ['forum_categories', 'forum_threads', 'forum_posts', 'forum_profiles', 'auth_users'],
));
