<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver\Config;

use NoDiscard;
use Pulsar\Api\Internal;

/**
 * Configuration DTO for the Google Cloud Pub/Sub queue driver.
 */
#[Internal(reason: 'Driver configuration; use QueueConfig for public access')]
final readonly class PubSubDriverConfig
{
    public function __construct(
        public string $projectId = '',
        public string $keyFilePath = '',
        public string $topicPrefix = 'pulsar-queue-',
        public string $subscriptionPrefix = 'pulsar-worker-',
    ) {}

    /**
     * @param array{
     *     project_id?: string,
     *     key_file_path?: string,
     *     topic_prefix?: string,
     *     subscription_prefix?: string,
     * } $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            projectId: $data['project_id'] ?? '',
            keyFilePath: $data['key_file_path'] ?? '',
            topicPrefix: $data['topic_prefix'] ?? 'pulsar-queue-',
            subscriptionPrefix: $data['subscription_prefix'] ?? 'pulsar-worker-',
        );
    }
}
