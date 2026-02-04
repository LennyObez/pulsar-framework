<?php

declare(strict_types=1);

/**
 * Multi-Tenancy Configuration
 *
 * Tenant resolution and database isolation settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | Override with the TENANCY_ENABLED environment variable.
    |
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Tenant Resolver Strategy
    |--------------------------------------------------------------------------
    |
    | How tenants are identified from incoming requests.
    | Options: 'header', 'subdomain', 'path'
    |
    */
    'resolver' => 'header',

    /*
    |--------------------------------------------------------------------------
    | Header Name
    |--------------------------------------------------------------------------
    |
    | The HTTP header used when resolver is 'header'.
    |
    */
    'header_name' => 'X-Tenant-ID',

    /*
    |--------------------------------------------------------------------------
    | Subdomain Suffix
    |--------------------------------------------------------------------------
    |
    | The base domain suffix when resolver is 'subdomain'.
    | Example: '.example.com' means 'acme.example.com' → tenant 'acme'.
    |
    */
    'subdomain_suffix' => '',

    /*
    |--------------------------------------------------------------------------
    | Path Prefix
    |--------------------------------------------------------------------------
    |
    | The URL path prefix when resolver is 'path'.
    | Example: '/t/' means '/t/acme/dashboard' → tenant 'acme'.
    |
    */
    'path_prefix' => '/t/',

    /*
    |--------------------------------------------------------------------------
    | Default Tenant
    |--------------------------------------------------------------------------
    |
    | Fallback tenant when no tenant can be resolved from the request.
    | Set to null to require explicit tenant identification.
    |
    */
    'default_tenant' => null,

    /*
    |--------------------------------------------------------------------------
    | Database Isolation
    |--------------------------------------------------------------------------
    |
    | Strategy for isolating tenant data at the database level.
    | Options: 'prefix', 'separate_connection', 'shared'
    |
    */
    'database' => [
        'strategy' => 'prefix',
        'prefix_template' => 'tenant_{tenant_id}_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Configured Tenants
    |--------------------------------------------------------------------------
    |
    | Map of tenant ID → tenant data. Each entry must include 'name'
    | and may include arbitrary metadata.
    |
    */
    'tenants' => [
        // 'acme' => [
        //     'name' => 'Acme Corp',
        //     'metadata' => ['plan' => 'enterprise'],
        // ],
    ],
];
