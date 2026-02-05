<?php

declare(strict_types=1);

/**
 * ORM extension configuration stub.
 *
 * @see \Pulsar\Extension\Orm\Config\OrmConfig
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Connection Name
    |--------------------------------------------------------------------------
    |
    | The database connection name to use for ORM operations.
    | Must match a connection defined in the database configuration.
    |
    */
    'connection' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Metadata Cache
    |--------------------------------------------------------------------------
    |
    | Controls how entity metadata is cached. In production, use 'file'
    | with a writable cache directory. In development, 'array' is fine.
    |
    */
    'metadata_cache' => [
        'driver' => 'array',
        'path' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | Configuration for encrypted column support. Requires the security
    | extension to provide EncryptorInterface and KeyProviderInterface.
    |
    */
    'encryption' => [
        'enabled' => false,
        'sub_key_id' => 5,
        'context' => 'orm__enc',
        'blind_index_context' => 'orm__bidx',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Column
    |--------------------------------------------------------------------------
    |
    | Default column name for tenant scoping. Entities decorated with
    | #[TenantScoped] will automatically filter by this column.
    |
    */
    'tenant_column' => 'tenant_id',

    /*
    |--------------------------------------------------------------------------
    | Soft Delete Column
    |--------------------------------------------------------------------------
    |
    | Default column name for soft deletes.
    |
    */
    'soft_delete_column' => 'deleted_at',
];
