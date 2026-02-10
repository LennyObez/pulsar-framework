<?php

/**
 * Minimal AMQP stub for Psalm static analysis.
 *
 * ext-amqp is optional (listed in composer.json suggest).
 * This stub provides just enough type information for Psalm
 * to analyze AmqpDriver.
 */

define('AMQP_NOPARAM', 0);
define('AMQP_DURABLE', 2);
define('AMQP_EX_TYPE_DIRECT', 'direct');

class AMQPConnection
{
    public function __construct() {}

    public function setHost(string $host): void {}

    public function setPort(int $port): void {}

    public function setLogin(string $login): void {}

    public function setPassword(string $password): void {}

    public function setVhost(string $vhost): void {}

    public function connect(): void {}
}

class AMQPChannel
{
    public function __construct(AMQPConnection $connection) {}
}

class AMQPExchange
{
    public function __construct(AMQPChannel $channel) {}

    public function setName(string $name): void {}

    public function setType(string $type): void {}

    public function setFlags(int $flags): void {}

    public function declareExchange(): void {}

    public function getName(): ?string {}

    /**
     * @param string $message
     * @param string $routingKey
     * @param int $flags
     * @param array<string, mixed> $attributes
     * @return bool
     */
    public function publish(string $message, string $routingKey = '', int $flags = 0, array $attributes = []): bool {}
}

class AMQPQueue
{
    public function __construct(AMQPChannel $channel) {}

    public function setName(string $name): void {}

    public function setFlags(int $flags): void {}

    /**
     * @param string $key
     * @param mixed $value
     */
    public function setArgument(string $key, mixed $value): void {}

    /** @return int Message count */
    public function declareQueue(): int {}

    public function bind(string $exchangeName, string $routingKey = ''): void {}

    /**
     * @param int $flags
     * @return AMQPEnvelope|false
     */
    public function get(int $flags = 0): AMQPEnvelope|false {}

    public function ack(int $deliveryTag, int $flags = 0): void {}

    public function nack(int $deliveryTag, int $flags = 0): void {}

    public function purge(): void {}
}

class AMQPEnvelope
{
    public function getBody(): string {}

    public function getDeliveryTag(): ?int {}

    public function getRoutingKey(): string {}
}
