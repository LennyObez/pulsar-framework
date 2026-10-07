<?php

declare(strict_types=1);

namespace App\Migration;

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Creates the `articles` table.
 *
 * Pulsar migrations implement MigrationInterface with up() and down()
 * methods. The MigrationRunner executes pending migrations in version
 * order and tracks state in a migrations table.
 */
final readonly class CreateArticlesTable implements MigrationInterface
{
    public function version(): string
    {
        return '2026_03_20_000001';
    }

    public function description(): string
    {
        return 'Create articles table';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                author VARCHAR(100) NOT NULL,
                published BOOLEAN NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS articles');
    }
}
