<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver\Config;

use NoDiscard;
use Pulsar\Api\Internal;

use function is_float;
use function is_int;
use function is_string;

/**
 * Configuration DTO for the Redis queue driver.
 */
#[Internal(reason: 'Driver configuration; use QueueConfig for public access')]
readonly class RedisDriverConfig
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
     * @param array<string, mixed> $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawHost = $data['host'] ?? '127.0.0.1';
        $rawPort = $data['port'] ?? 6379;
        $rawPassword = $data['password'] ?? '';
        $rawDatabase = $data['database'] ?? 0;
        $rawPrefix = $data['prefix'] ?? 'queue:';
        $rawTimeout = $data['timeout'] ?? 0.0;

        return new self(
            host: is_string($rawHost) ? $rawHost : '127.0.0.1',
            port: is_int($rawPort) ? $rawPort : 6379,
            password: is_string($rawPassword) ? $rawPassword : '',
            database: is_int($rawDatabase) ? $rawDatabase : 0,
            prefix: is_string($rawPrefix) ? $rawPrefix : 'queue:',
            timeout: is_int($rawTimeout) || is_float($rawTimeout) ? (float) $rawTimeout : 0.0,
        );
    }
}
