<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_int;
use function is_string;

/**
 * Configuration for the persistent HTTP runtime.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RuntimeConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 8080,
        public int $maxRequests = 10_000,
        public int $memoryThresholdMb = 256,
        public int $timeLimitSeconds = 7200,
        public bool $keepAlive = true,
        public int $keepAliveTimeout = 15,
        public int $headerTimeoutSeconds = 15,
        public int $bodyTimeoutSeconds = 60,
        public int $fiberConcurrency = 0,
        public int $maxHeaderSize = 8192,
        public int $maxBodySize = 10_485_760,
        public bool $addDateHeader = true,
        public string $driver = 'auto',
        public int $drainTimeoutSeconds = 30,
        public bool $healthEndpoint = true,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $host = $environment->get('RUNTIME_HOST');
        $hostValue = is_string($data['host'] ?? null) ? $data['host'] : '127.0.0.1';

        $driver = $environment->get('RUNTIME_DRIVER');
        $driverValue = is_string($data['driver'] ?? null) ? $data['driver'] : 'auto';

        return new self(
            host: $host ?? $hostValue,
            port: self::envInt($environment, 'RUNTIME_PORT')
                ?? self::int($data, 'port', 8080),
            maxRequests: self::envInt($environment, 'RUNTIME_MAX_REQUESTS')
                ?? self::int($data, 'max_requests', 10_000),
            memoryThresholdMb: self::envInt($environment, 'RUNTIME_MEMORY_THRESHOLD_MB')
                ?? self::int($data, 'memory_threshold_mb', 256),
            timeLimitSeconds: self::envInt($environment, 'RUNTIME_TIME_LIMIT_SECONDS')
                ?? self::int($data, 'time_limit_seconds', 7200),
            keepAlive: self::bool($data, 'keep_alive'),
            keepAliveTimeout: self::int($data, 'keep_alive_timeout', 15),
            headerTimeoutSeconds: self::int($data, 'header_timeout_seconds', 15),
            bodyTimeoutSeconds: self::int($data, 'body_timeout_seconds', 60),
            fiberConcurrency: self::envInt($environment, 'RUNTIME_FIBER_CONCURRENCY')
                ?? self::int($data, 'fiber_concurrency', 0),
            maxHeaderSize: self::int($data, 'max_header_size', 8192),
            maxBodySize: self::int($data, 'max_body_size', 10_485_760),
            addDateHeader: self::bool($data, 'add_date_header'),
            driver: $driver ?? $driverValue,
            drainTimeoutSeconds: self::envInt($environment, 'RUNTIME_DRAIN_TIMEOUT_SECONDS')
                ?? self::int($data, 'drain_timeout_seconds', 30),
            healthEndpoint: self::bool($data, 'health_endpoint'),
        );
    }

    private static function envInt(Environment $environment, string $key): ?int
    {
        $value = $environment->get($key);

        return $value !== null ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function int(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : true;
    }
}
