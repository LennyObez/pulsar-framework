<?php

declare(strict_types=1);

/**
 * Storage Configuration
 *
 * Filesystem and object storage settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Disk
    |--------------------------------------------------------------------------
    |
    | The default storage disk to use.
    | Override with the STORAGE_DISK environment variable.
    |
    */
    'default' => 'local',

    /*
    |--------------------------------------------------------------------------
    | Storage Disks
    |--------------------------------------------------------------------------
    |
    | Each disk defines a driver and its configuration.
    |
    | A local disk's `root` is resolved at boot and refused if it lands inside
    | the public document root, where every file written to it would become a
    | URL. A relative root is resolved against PULSAR_BASE_PATH (or the process
    | CWD when unset), so set that variable to the project root in production.
    |
    | `visibility` is what decides that: `private` (the default, and anything
    | other than the exact string `public`) keeps the check on; `public` is the
    | operator declaring the disk is meant to be served — generated assets, CMS
    | media — and waives it for that disk alone. Uploads accepted from users do
    | not belong on a public disk.
    |
    */
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => 'storage/app',
            'visibility' => 'private',
        ],

        's3' => [
            'driver' => 's3',
            'region' => 'us-east-1',
            'bucket' => '',
            'prefix' => '',
            'endpoint' => null,
            'use_path_style' => false,
        ],
    ],
];
