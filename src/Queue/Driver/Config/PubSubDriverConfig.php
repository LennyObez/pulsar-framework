<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver\Config;

use NoDiscard;
use Pulsar\Api\Internal;

use function is_string;

/**
 * Configuration DTO for the Google Cloud Pub/Sub queue driver.
 */
#[Internal(reason: 'Driver configuration; use QueueConfig for public access')]
readonly class PubSubDriverConfig
{
    public function __construct(
        public string $projectId = '',
        public string $keyFilePath = '',
        public string $topicPrefix = 'pulsar-queue-',
        public string $subscriptionPrefix = 'pulsar-worker-',
    ) {}

    /**
     * @param array<string, mixed> $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawProjectId = $data['project_id'] ?? '';
        $rawKeyFilePath = $data['key_file_path'] ?? '';
        $rawTopicPrefix = $data['topic_prefix'] ?? 'pulsar-queue-';
        $rawSubscriptionPrefix = $data['subscription_prefix'] ?? 'pulsar-worker-';

        return new self(
            projectId: is_string($rawProjectId) ? $rawProjectId : '',
            keyFilePath: is_string($rawKeyFilePath) ? $rawKeyFilePath : '',
            topicPrefix: is_string($rawTopicPrefix) ? $rawTopicPrefix : 'pulsar-queue-',
            subscriptionPrefix: is_string($rawSubscriptionPrefix) ? $rawSubscriptionPrefix : 'pulsar-worker-',
        );
    }
}
