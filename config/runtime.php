<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Host
    |--------------------------------------------------------------------------
    |
    | Address to bind. Default 127.0.0.1 (loopback only).
    | Binding to 0.0.0.0 requires the --public flag on runtime:serve.
    |
    | Env override: RUNTIME_HOST
    |
    */
    'host' => '127.0.0.1',

    /*
    |--------------------------------------------------------------------------
    | Port
    |--------------------------------------------------------------------------
    |
    | TCP port to listen on.
    |
    | Env override: RUNTIME_PORT
    |
    */
    'port' => 8080,

    /*
    |--------------------------------------------------------------------------
    | Max Requests
    |--------------------------------------------------------------------------
    |
    | Maximum requests before recycling the worker.
    |
    | Env override: RUNTIME_MAX_REQUESTS
    |
    */
    'max_requests' => 10_000,

    /*
    |--------------------------------------------------------------------------
    | Memory Threshold (MB)
    |--------------------------------------------------------------------------
    |
    | Recycle when memory usage exceeds this value.
    |
    | Env override: RUNTIME_MEMORY_THRESHOLD_MB
    |
    */
    'memory_threshold_mb' => 256,

    /*
    |--------------------------------------------------------------------------
    | Time Limit (seconds)
    |--------------------------------------------------------------------------
    |
    | Recycle the worker after this many seconds of uptime.
    |
    | Env override: RUNTIME_TIME_LIMIT_SECONDS
    |
    */
    'time_limit_seconds' => 7200,

    /*
    |--------------------------------------------------------------------------
    | Keep-Alive
    |--------------------------------------------------------------------------
    |
    | Enable HTTP/1.1 keep-alive connections.
    |
    */
    'keep_alive' => true,

    /*
    |--------------------------------------------------------------------------
    | Keep-Alive Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Idle timeout between requests on a keep-alive connection.
    |
    */
    'keep_alive_timeout' => 15,

    /*
    |--------------------------------------------------------------------------
    | Header Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum time to receive complete request headers (slowloris defense).
    |
    */
    'header_timeout_seconds' => 15,

    /*
    |--------------------------------------------------------------------------
    | Body Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum time to receive the complete request body.
    |
    */
    'body_timeout_seconds' => 60,

    /*
    |--------------------------------------------------------------------------
    | Fiber Concurrency
    |--------------------------------------------------------------------------
    |
    | Number of concurrent Fiber connections. 0 = synchronous accept loop.
    |
    | Env override: RUNTIME_FIBER_CONCURRENCY
    |
    */
    'fiber_concurrency' => 0,

    /*
    |--------------------------------------------------------------------------
    | Max Header Size (bytes)
    |--------------------------------------------------------------------------
    |
    | Maximum allowed size for request headers (including request line).
    |
    */
    'max_header_size' => 8192,

    /*
    |--------------------------------------------------------------------------
    | Max Body Size (bytes)
    |--------------------------------------------------------------------------
    |
    | Maximum allowed size for request body (10 MB default).
    |
    */
    'max_body_size' => 10_485_760,

    /*
    |--------------------------------------------------------------------------
    | Add Date Header
    |--------------------------------------------------------------------------
    |
    | Whether the runtime adds a Date header to responses.
    | Disable if your reverse proxy sets it.
    |
    */
    'add_date_header' => true,

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Runtime driver to use. Set to "auto" for automatic detection, or
    | choose explicitly: "fpm", "persistent", "frankenphp", "roadrunner".
    |
    | Auto-detection priority: frankenphp > roadrunner > persistent > fpm.
    |
    | Env override: RUNTIME_DRIVER
    |
    */
    'driver' => 'auto',

    /*
    |--------------------------------------------------------------------------
    | Drain Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum time to wait for in-flight requests to complete during a
    | graceful reload or shutdown. After this timeout, remaining requests
    | are forcefully terminated.
    |
    | Env override: RUNTIME_DRAIN_TIMEOUT_SECONDS
    |
    */
    'drain_timeout_seconds' => 30,

    /*
    |--------------------------------------------------------------------------
    | Health Endpoint
    |--------------------------------------------------------------------------
    |
    | Enable the built-in /_health endpoint for load-balancer probes.
    | Disable if your infrastructure uses a custom health-check path.
    |
    */
    'health_endpoint' => true,
];
