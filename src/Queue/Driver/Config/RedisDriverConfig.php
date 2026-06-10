<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;

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
            host: Coerce::string($data['host'] ?? null, '127.0.0.1'),
            port: Coerce::int($data['port'] ?? null, 6379),
            password: Coerce::string($data['password'] ?? null),
            database: Coerce::int($data['database'] ?? null, 0),
            prefix: Coerce::string($data['prefix'] ?? null, 'queue:'),
            timeout: Coerce::float($data['timeout'] ?? null, 0.0),
        );
    }
}
