<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver\Config;

use NoDiscard;
use Pulsar\Api\Internal;

/**
 * Configuration DTO for the Redis queue driver.
 */
#[Internal(reason: 'Driver configuration; use QueueConfig for public access')]
final readonly class RedisDriverConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 6379,
        public string $password = '',
        public int $database = 0,
        public string $prefix = 'queue:',
        public float $timeout = 0.0,
    ) {}

    /**
     * @param array{
     *     host?: string,
     *     port?: int,
     *     password?: string,
     *     database?: int,
     *     prefix?: string,
     *     timeout?: float|int,
     * } $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            host: $data['host'] ?? '127.0.0.1',
            port: $data['port'] ?? 6379,
            password: $data['password'] ?? '',
            database: $data['database'] ?? 0,
            prefix: $data['prefix'] ?? 'queue:',
            timeout: (float) ($data['timeout'] ?? 0.0),
        );
    }
}
