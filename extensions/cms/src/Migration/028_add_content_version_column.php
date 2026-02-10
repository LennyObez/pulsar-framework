<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE cms_contents ADD COLUMN version INTEGER NOT NULL DEFAULT 1
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE cms_contents DROP COLUMN version
            SQL);
    }
};
