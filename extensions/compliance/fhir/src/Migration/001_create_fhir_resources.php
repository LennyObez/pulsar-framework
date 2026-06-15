<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Persistence schema for {@see \Pulsar\Extension\Fhir\Internal\DatabaseFhirRepository}.
 *
 * One row per FHIR resource, keyed by (resource_type, resource_id). The full
 * resource is stored as JSON in `content`; `version_id` and `last_updated`
 * mirror the resource `meta`; `is_deleted` implements logical deletes.
 * Portable DDL: VARCHAR/TEXT/INTEGER and IF NOT EXISTS are supported by
 * SQLite, MySQL, and PostgreSQL alike.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS fhir_resources (
                resource_type VARCHAR(64) NOT NULL,
                resource_id VARCHAR(64) NOT NULL,
                version_id INTEGER NOT NULL DEFAULT 1,
                last_updated VARCHAR(40) NOT NULL,
                content TEXT NOT NULL,
                is_deleted INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (resource_type, resource_id)
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_fhir_type_active_updated
                ON fhir_resources (resource_type, is_deleted, last_updated)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS fhir_resources');
    }
};
