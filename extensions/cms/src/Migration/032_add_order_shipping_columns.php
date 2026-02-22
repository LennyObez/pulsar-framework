<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE cms_orders ADD COLUMN shipping_amount BIGINT NOT NULL DEFAULT 0
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE cms_orders ADD COLUMN shipping_method VARCHAR(20) DEFAULT NULL
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE cms_orders DROP COLUMN shipping_method
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE cms_orders DROP COLUMN shipping_amount
            SQL);
    }
};
