<?php

declare(strict_types=1);

/**
 * Database Configuration
 *
 * Connection and migration settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    |
    | The name of the default connection from the list below.
    | Override with the DB_CONNECTION environment variable.
    |
    */
    'default' => 'sqlite',

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Each connection defines driver, host, port, database name, credentials,
    | and charset. Environment variables DB_HOST, DB_PORT, DB_DATABASE,
    | DB_USERNAME, and DB_PASSWORD override values for the active connection.
    |
    */
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'pulsar',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'pulsar',
            'username' => 'postgres',
            'password' => '',
            'charset' => 'utf8',
            'collation' => '',
            'options' => [],
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'host' => '',
            'port' => 0,
            'database' => 'database/pulsar.sqlite',
            'username' => '',
            'password' => '',
            'charset' => 'utf8',
            'collation' => '',
            'options' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | Table name for tracking applied migrations and the directory path
    | where migration files are stored.
    |
    */
    'migrations' => [
        'table' => 'pulsar_migrations',
        'path' => 'database/migrations',
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Pool (Persistent Runtimes Only)
    |--------------------------------------------------------------------------
    |
    | Connection pooling is active only under persistent runtimes (Swoole,
    | RoadRunner, etc.). Under FPM, these settings are ignored and normal
    | per-request connections are used.
    |
    */
    'pool' => [
        'min_connections' => 2,
        'max_connections' => 10,
        'idle_timeout_seconds' => 60,
        'max_lifetime_seconds' => 3600,
        'health_check_interval_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Read/Write Routing
    |--------------------------------------------------------------------------
    |
    | Enable read/write splitting to route SELECT queries to read replicas
    | and write queries to the primary. After a write, reads are pinned to
    | the primary for the configured sticky duration.
    |
    */
    'read_write' => [
        'enabled' => false,
        'write_host' => '',
        'read_hosts' => [],
        'sticky_duration' => 'request',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failover
    |--------------------------------------------------------------------------
    |
    | Failover detection and switching. Pulsar detects primary failures and
    | switches to a new endpoint — it does NOT promote replicas. Promotion
    | is the infrastructure/orchestrator's responsibility.
    |
    */
    'failover' => [
        'enabled' => false,
        'failure_threshold' => 3,
        'retry_interval_seconds' => 5,
        'strategy' => 'dns',
        'compliance_events_enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Cache
    |--------------------------------------------------------------------------
    |
    | Query result caching via PSR-16. Disabled by default in the regulated
    | preset. Sensitive tables and authorization-shaped queries are never
    | cached regardless of settings.
    |
    */
    'query_cache' => [
        'enabled' => true,
        'default_ttl_seconds' => 60,
        'sensitive_table_names' => [],
        'authorization_columns' => ['user_id', 'tenant_id'],
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Monitoring
    |--------------------------------------------------------------------------
    |
    | Safe SQL logging, slow query detection, and connection auditing.
    | Raw bindings are never logged in production. Debug mode requires
    | both a config flag and the DB_LOG_RAW_BINDINGS=CONFIRM_UNSAFE
    | environment variable.
    |
    */
    'monitor' => [
        'slow_query_threshold_ms' => 1000,
        'log_raw_bindings' => false,
        'require_environment_confirmation' => true,
        'pii_columns' => [],
    ],
];
