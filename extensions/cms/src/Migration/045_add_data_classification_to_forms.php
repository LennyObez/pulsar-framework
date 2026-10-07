<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Add data_classification column to cms_form_submissions.
 *
 * Marks each submission with its data sensitivity level so that encryption,
 * retention, and access-control policies can be applied automatically. Form
 * submissions default to 'pii' because they typically contain user-provided
 * personal data (names, emails, addresses).
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE cms_form_submissions
                ADD COLUMN data_classification VARCHAR(20) NOT NULL DEFAULT 'pii'
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => null,
            Driver::MySQL => $connection->execute(
                'ALTER TABLE cms_form_submissions DROP COLUMN data_classification',
            ),
            Driver::PostgreSQL => $connection->execute(
                'ALTER TABLE cms_form_submissions DROP COLUMN IF EXISTS data_classification',
            ),
        };
    }
};
