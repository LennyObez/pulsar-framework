<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver\Config;

use NoDiscard;
use Pulsar\Api\Internal;

use function is_int;
use function is_string;

/**
 * Configuration DTO for the AMQP queue driver.
 */
#[Internal(reason: 'Driver configuration; use QueueConfig for public access')]
readonly class AmqpDriverConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 5672,
        public string $user = 'guest',
        public string $password = 'guest',
        public string $vhost = '/',
        public string $exchange = 'pulsar.queue',
    ) {}

    /**
     * @param array<string, mixed> $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawHost = $data['host'] ?? '127.0.0.1';
        $rawPort = $data['port'] ?? 5672;
        $rawUser = $data['user'] ?? 'guest';
        $rawPassword = $data['password'] ?? 'guest';
        $rawVhost = $data['vhost'] ?? '/';
        $rawExchange = $data['exchange'] ?? 'pulsar.queue';

        return new self(
            host: is_string($rawHost) ? $rawHost : '127.0.0.1',
            port: is_int($rawPort) ? $rawPort : 5672,
            user: is_string($rawUser) ? $rawUser : 'guest',
            password: is_string($rawPassword) ? $rawPassword : 'guest',
            vhost: is_string($rawVhost) ? $rawVhost : '/',
            exchange: is_string($rawExchange) ? $rawExchange : 'pulsar.queue',
        );
    }
}
