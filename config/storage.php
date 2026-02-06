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
