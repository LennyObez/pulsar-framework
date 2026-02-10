<?php

/**
 * Minimal Google Cloud Pub/Sub stub for PHPStan and Psalm static analysis.
 *
 * google/cloud-pubsub is optional (listed in composer.json suggest).
 * This stub provides just enough type information for static
 * analyzers to analyze PubSubDriver.
 */

namespace Google\Cloud\PubSub;

class PubSubClient
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = []) {}

    public function topic(string $name): Topic {}

    public function subscription(string $name, string $topicName = ''): Subscription {}
}

class Topic
{
    public function exists(): bool {}

    public function create(): void {}

    public function name(): string {}

    /**
     * @param array<string, mixed> $message
     * @return array<string, string>
     */
    public function publish(array $message): array {}
}

class Subscription
{
    public function exists(): bool {}

    public function create(): void {}

    /**
     * @param array<string, mixed> $options
     * @return list<Message>
     */
    public function pull(array $options = []): array {}

    public function acknowledge(Message $message): void {}

    public function modifyAckDeadline(Message $message, int $seconds): void {}

    /**
     * @param array<string, mixed> $options
     */
    public function seek(array $options): void {}
}

class Message
{
    public function data(): string {}

    /**
     * @return array<string, string>
     */
    public function attributes(): array {}

    public function id(): string {}
}
