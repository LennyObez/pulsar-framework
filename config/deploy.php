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

    /*
    |--------------------------------------------------------------------------
    | Deploy Checks
    |--------------------------------------------------------------------------
    |
    | Per-check configuration for `deploy:check`. Each check can be enabled/disabled
    | and assigned a severity level: 'fail' (blocking error), 'warn' (advisory),
    | or 'off' (disabled entirely).
    |
    | Env override: DEPLOY_CHECK_{NAME}_SEVERITY=fail|warn|off
    | (name is uppercased, hyphens become underscores)
    |
    */
    'checks' => [
        'debug-mode' => ['enabled' => true, 'severity' => 'fail'],
        'opcache' => ['enabled' => true, 'severity' => 'fail'],
        'jit' => ['enabled' => true, 'severity' => 'warn'],
        'cache-settings' => ['enabled' => true, 'severity' => 'fail'],
        'filesystem-scan' => ['enabled' => true, 'severity' => 'fail'],
        'security-headers' => ['enabled' => true, 'severity' => 'fail'],
        'https-readiness' => ['enabled' => true, 'severity' => 'warn'],
        'http3-readiness' => ['enabled' => true, 'severity' => 'warn'],
        'health-endpoint' => ['enabled' => true, 'severity' => 'warn'],
        'rate-limiting' => ['enabled' => true, 'severity' => 'warn'],
        'request-size-limits' => ['enabled' => true, 'severity' => 'warn'],
        'trusted-proxies' => ['enabled' => true, 'severity' => 'warn'],
        'integrity' => ['enabled' => true, 'severity' => 'fail'],
    ],
];
