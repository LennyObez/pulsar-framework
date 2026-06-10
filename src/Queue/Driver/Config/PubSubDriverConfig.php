<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;

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
            projectId: Coerce::string($data['project_id'] ?? null),
            keyFilePath: Coerce::string($data['key_file_path'] ?? null),
            topicPrefix: Coerce::string($data['topic_prefix'] ?? null, 'pulsar-queue-'),
            subscriptionPrefix: Coerce::string($data['subscription_prefix'] ?? null, 'pulsar-worker-'),
        );
    }
}
