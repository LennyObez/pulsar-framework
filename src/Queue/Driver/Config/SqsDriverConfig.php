<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver\Config;

use NoDiscard;
use Pulsar\Api\Internal;

use function is_string;

/**
 * Configuration DTO for the Amazon SQS queue driver.
 */
#[Internal(reason: 'Driver configuration — use QueueConfig for public access')]
readonly class SqsDriverConfig
{
    public function __construct(
        public string $region = 'us-east-1',
        public string $key = '',
        public string $secret = '',
        public string $prefix = '',
    ) {}

    /**
     * @param array<string, mixed> $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawRegion = $data['region'] ?? 'us-east-1';
        $rawKey = $data['key'] ?? '';
        $rawSecret = $data['secret'] ?? '';
        $rawPrefix = $data['prefix'] ?? '';

        return new self(
            region: is_string($rawRegion) ? $rawRegion : 'us-east-1',
            key: is_string($rawKey) ? $rawKey : '',
            secret: is_string($rawSecret) ? $rawSecret : '',
            prefix: is_string($rawPrefix) ? $rawPrefix : '',
        );
    }
}
