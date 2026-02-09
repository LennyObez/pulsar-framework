<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => $this->upSqlite($connection),
            Driver::MySQL => $this->upMysql($connection),
            Driver::PostgreSQL => null, // PostgreSQL already uses tsvector via search_vector column
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => $connection->execute('DROP TABLE IF EXISTS cms_content_fts'),
            Driver::MySQL => $this->downMysql($connection),
            Driver::PostgreSQL => null,
        };
    }

    private function upSqlite(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE VIRTUAL TABLE IF NOT EXISTS cms_content_fts USING fts5(
                title,
                body,
                content='cms_content_translations',
                content_rowid='rowid'
            )
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE cms_content_translations
                ADD FULLTEXT INDEX ft_cms_content_translations_title_body (title, body)
            SQL);
    }

    private function downMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE cms_content_translations
                DROP INDEX ft_cms_content_translations_title_body
            SQL);
    }
};
