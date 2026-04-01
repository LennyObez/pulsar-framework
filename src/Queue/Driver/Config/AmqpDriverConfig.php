<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver\Config;

use NoDiscard;
use Pulsar\Api\Internal;

/**
 * Configuration DTO for the AMQP queue driver.
 */
#[Internal(reason: 'Driver configuration; use QueueConfig for public access')]
final readonly class AmqpDriverConfig
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
     * @param array{
     *     host?: string,
     *     port?: int,
     *     user?: string,
     *     password?: string,
     *     vhost?: string,
     *     exchange?: string,
     * } $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            host: $data['host'] ?? '127.0.0.1',
            port: $data['port'] ?? 5672,
            user: $data['user'] ?? 'guest',
            password: $data['password'] ?? 'guest',
            vhost: $data['vhost'] ?? '/',
            exchange: $data['exchange'] ?? 'pulsar.queue',
        );
    }
}
