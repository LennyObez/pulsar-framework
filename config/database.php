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
];
