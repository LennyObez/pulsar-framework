<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_content_type_fields (
                id VARCHAR(36) NOT NULL,
                content_type VARCHAR(100) NOT NULL,
                field_key VARCHAR(100) NOT NULL,
                field_type VARCHAR(20) NOT NULL,
                required BOOLEAN NOT NULL DEFAULT FALSE,
                translatable BOOLEAN NOT NULL DEFAULT FALSE,
                searchable BOOLEAN NOT NULL DEFAULT FALSE,
                filterable BOOLEAN NOT NULL DEFAULT FALSE,
                sortable BOOLEAN NOT NULL DEFAULT FALSE,
                validation_rules JSONB DEFAULT NULL,
                default_value JSONB DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                CONSTRAINT chk_field_type CHECK (field_type IN (
                    'string', 'int', 'float', 'bool', 'date', 'datetime',
                    'enum', 'relation', 'json', 'media', 'rich_text',
                    'color', 'url', 'email'
                ))
            )
            SQL, $driver));

        $indexes->ensure(
            'cms_content_type_fields',
            'uq_content_type_field_key',
            ['content_type', 'field_key'],
            unique: true,
        );

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_content_field_values (
                id VARCHAR(36) NOT NULL,
                content_id VARCHAR(36) NOT NULL,
                field_id VARCHAR(36) NOT NULL,
                locale VARCHAR(5) DEFAULT NULL,
                value_string VARCHAR(1000) DEFAULT NULL,
                value_int BIGINT DEFAULT NULL,
                value_float DOUBLE PRECISION DEFAULT NULL,
                value_bool BOOLEAN DEFAULT NULL,
                value_datetime TIMESTAMPTZ DEFAULT NULL,
                value_json JSONB DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_field_value_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE CASCADE,
                CONSTRAINT fk_field_value_field FOREIGN KEY (field_id) REFERENCES cms_content_type_fields (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_field_value_content_field_locale
                ON cms_content_field_values (content_id, field_id, COALESCE(locale, ''))
            SQL);

        $indexes->ensure('cms_content_field_values', 'idx_field_value_string', ['field_id', 'value_string']);

        $indexes->ensure('cms_content_field_values', 'idx_field_value_int', ['field_id', 'value_int']);

        $indexes->ensure('cms_content_field_values', 'idx_field_value_datetime', ['field_id', 'value_datetime']);

        $indexes->ensure('cms_content_field_values', 'idx_field_value_bool', ['field_id', 'value_bool']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_content_field_values');
        $connection->execute('DROP TABLE IF EXISTS cms_content_type_fields');
    }
};
