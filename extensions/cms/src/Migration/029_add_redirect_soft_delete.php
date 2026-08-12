<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        $connection->execute(<<<'SQL'
            ALTER TABLE cms_redirects ADD COLUMN deleted_at TEXT DEFAULT NULL
            SQL);

        $indexes->ensure('cms_redirects', 'idx_cms_redirects_deleted_at', ['deleted_at']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);
        $driver = $connection->driver();

        if ($driver !== Driver::SQLite) {
            $indexes->ensureAbsent('cms_redirects', 'idx_cms_redirects_deleted_at');
            $connection->execute('ALTER TABLE cms_redirects DROP COLUMN deleted_at');
        }
    }
};
