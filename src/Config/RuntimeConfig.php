<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for the persistent HTTP runtime.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RuntimeConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/runtime.php. */
    private const array KNOWN_KEYS = [
        'driver', 'host', 'port', 'fiber_concurrency', 'max_requests', 'memory_threshold_mb',
        'time_limit_seconds', 'max_body_size', 'max_header_size', 'header_timeout_seconds',
        'body_timeout_seconds', 'keep_alive', 'keep_alive_timeout', 'drain_timeout_seconds',
        'add_date_header', 'health_endpoint',
    ];

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
        /** @var list<string> */
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * @param array{
     *     host?: string,
     *     port?: int,
     *     max_requests?: int,
     *     memory_threshold_mb?: int,
     *     time_limit_seconds?: int,
     *     keep_alive?: bool,
     *     keep_alive_timeout?: int,
     *     header_timeout_seconds?: int,
     *     body_timeout_seconds?: int,
     *     fiber_concurrency?: int,
     *     max_header_size?: int,
     *     max_body_size?: int,
     *     add_date_header?: bool,
     *     driver?: string,
     *     drain_timeout_seconds?: int,
     *     health_endpoint?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        return new self(
            host: $environment->get('RUNTIME_HOST') ?? $data['host'] ?? '127.0.0.1',
            port: self::envInt($environment, 'RUNTIME_PORT') ?? $data['port'] ?? 8080,
            maxRequests: self::envInt($environment, 'RUNTIME_MAX_REQUESTS') ?? $data['max_requests'] ?? 10_000,
            memoryThresholdMb: self::envInt($environment, 'RUNTIME_MEMORY_THRESHOLD_MB') ?? $data['memory_threshold_mb'] ?? 256,
            timeLimitSeconds: self::envInt($environment, 'RUNTIME_TIME_LIMIT_SECONDS') ?? $data['time_limit_seconds'] ?? 7200,
            keepAlive: $data['keep_alive'] ?? true,
            keepAliveTimeout: $data['keep_alive_timeout'] ?? 15,
            headerTimeoutSeconds: $data['header_timeout_seconds'] ?? 15,
            bodyTimeoutSeconds: $data['body_timeout_seconds'] ?? 60,
            fiberConcurrency: self::envInt($environment, 'RUNTIME_FIBER_CONCURRENCY') ?? $data['fiber_concurrency'] ?? 0,
            maxHeaderSize: $data['max_header_size'] ?? 8192,
            maxBodySize: $data['max_body_size'] ?? 10_485_760,
            addDateHeader: $data['add_date_header'] ?? true,
            driver: $environment->get('RUNTIME_DRIVER') ?? $data['driver'] ?? 'auto',
            drainTimeoutSeconds: self::envInt($environment, 'RUNTIME_DRAIN_TIMEOUT_SECONDS') ?? $data['drain_timeout_seconds'] ?? 30,
            healthEndpoint: $data['health_endpoint'] ?? true,
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }

    private static function envInt(Environment $environment, string $key): ?int
    {
        $value = $environment->get($key);

        return $value !== null ? (int) $value : null;
    }
}
