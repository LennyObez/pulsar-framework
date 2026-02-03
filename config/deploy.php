<?php

declare(strict_types=1);

/**
 * Deployment Configuration
 *
 * Deployment readiness check settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | IP addresses or CIDR ranges of trusted reverse proxies.
    | Required when running behind a load balancer or CDN.
    |
    */
    'trusted_proxies' => [],

    /*
    |--------------------------------------------------------------------------
    | Request Size Limits
    |--------------------------------------------------------------------------
    |
    | Maximum allowed sizes for incoming requests.
    |
    */
    'request_limits' => [
        'max_post_size_mb' => 8,
        'max_upload_size_mb' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP/3 (Alt-Svc)
    |--------------------------------------------------------------------------
    |
    | Optional HTTP/3 advertisement via Alt-Svc header.
    | Requires infrastructure support; this is a config readiness check only.
    |
    */
    'http3' => [
        'enabled' => false,
        'alt_svc_max_age' => 86400,
    ],
];
