<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;

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
            host: Coerce::string($data['host'] ?? null, '127.0.0.1'),
            port: Coerce::int($data['port'] ?? null, 5672),
            user: Coerce::string($data['user'] ?? null, 'guest'),
            password: Coerce::string($data['password'] ?? null, 'guest'),
            vhost: Coerce::string($data['vhost'] ?? null, '/'),
            exchange: Coerce::string($data['exchange'] ?? null, 'pulsar.queue'),
        );
    }

    /**
     * Prevent the broker password from leaking in debug output.
     *
     * @return array<string, string|int>
     */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'password' => $this->password !== '' ? '[REDACTED]' : '',
            'vhost' => $this->vhost,
            'exchange' => $this->exchange,
        ];
    }
}
