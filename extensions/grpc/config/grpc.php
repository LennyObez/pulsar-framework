<?php

declare(strict_types=1);

/**
 * gRPC Extension Configuration.
 *
 * Configures the gRPC server, transport adapters, interceptors,
 * mTLS identity mapping, and reflection settings.
 *
 * @package Pulsar\Extension\Grpc
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Server
    |--------------------------------------------------------------------------
    */
    'host' => '0.0.0.0',
    'port' => 50051,
    'max_workers' => 4,
    'max_concurrent_streams' => 100,
    'keep_alive_interval_seconds' => 60,
    'keep_alive_timeout_seconds' => 20,

    /*
    |--------------------------------------------------------------------------
    | Transport Adapter
    |--------------------------------------------------------------------------
    | Supported: "grpc_extension", "roadrunner"
    */
    'adapter' => 'grpc_extension',

    /*
    |--------------------------------------------------------------------------
    | TLS / mTLS
    |--------------------------------------------------------------------------
    */
    'tls' => [
        'enabled' => false,
        'cert_path' => '',
        'key_path' => '',
        'ca_path' => '',
        'mutual' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | mTLS Service Identity Mapping
    |--------------------------------------------------------------------------
    | Maps certificate SANs to service identities and permissions.
    | Compiled at build time — no runtime certificate field interpretation.
    */
    'identity_map' => [
        // 'service-a.internal' => [
        //     'name' => 'service-a',
        //     'trust_level' => 'internal',
        //     'allowed_methods' => ['*'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Interceptors
    |--------------------------------------------------------------------------
    | Fixed order: Tracing -> Auth -> RateLimit -> Validation -> Logging
    | Individual interceptors can be disabled but order cannot be changed.
    */
    'interceptors' => [
        'tracing' => true,
        'auth' => true,
        'rate_limit' => true,
        'validation' => true,
        'logging' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */
    'rate_limit' => [
        'max_requests_per_second' => 1000,
        'burst_size' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reflection
    |--------------------------------------------------------------------------
    | Server reflection allows tools like grpcurl to discover services.
    | Disabled by default in production — enabling emits a security event.
    */
    'reflection' => [
        'enabled' => false,
        'allow_in_production' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check
    |--------------------------------------------------------------------------
    */
    'health' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Codegen (protoc wrapper)
    |--------------------------------------------------------------------------
    */
    'codegen' => [
        'proto_path' => 'proto',
        'output_path' => 'src/Generated',
        'protoc_binary' => 'protoc',
        'grpc_php_plugin' => 'grpc_php_plugin',
    ],
];
