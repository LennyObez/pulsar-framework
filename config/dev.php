<?php

declare(strict_types=1);

/**
 * Docker development environment configuration.
 *
 * @see \Pulsar\Console\Command\Dev\DevConfig
 */
return [
    // PHP version for the Docker container
    'php_version' => '8.5',

    // Database driver: 'pgsql', 'mysql', or 'sqlite'
    'database' => 'pgsql',

    // Include a Redis service
    'redis' => true,

    // Include Mailpit for email testing
    'mailpit' => true,

    // HTTP server driver: 'frankenphp' or 'built-in'
    'server_driver' => 'built-in',

    // Host port mappings
    'app_port' => 8080,
    'db_port' => 5432,
    'redis_port' => 6379,
    'mailpit_smtp_port' => 1025,
    'mailpit_web_port' => 8025,

    // PHP memory limit in MB
    'memory_limit' => 512,

    // Enable Xdebug for step debugging and coverage
    'xdebug' => true,

    // Additional PHP extensions to install
    'php_extensions' => [],

    // Docker Compose project name
    'project_name' => 'pulsar',
];
