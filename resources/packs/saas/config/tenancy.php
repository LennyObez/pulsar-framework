<?php

declare(strict_types=1);

/**
 * Multi-tenant configuration.
 *
 * Defines the tenant isolation strategy and how tenants are resolved
 * from incoming requests.
 *
 * WARNING: This is a scaffold configuration. Conduct a thorough security
 * audit of tenant isolation before deploying to production.
 */
return [
    'tenancy' => [
        'enabled' => true,

        'strategy' => 'database_column',

        'resolution' => [
            'method' => 'subdomain',
            'header' => 'X-Tenant-ID',
            'query_param' => 'tenant',
        ],

        'isolation' => [
            'enforce_on_queries' => true,
            'enforce_on_cache' => true,
            'enforce_on_storage' => true,
            'enforce_on_queue' => true,
        ],

        'defaults' => [
            'max_users_per_tenant' => 50,
            'max_storage_mb' => 5120,
        ],
    ],
];
