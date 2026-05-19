<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver\Config;

use NoDiscard;
use Pulsar\Api\Internal;

/**
 * Configuration DTO for the Amazon SQS queue driver.
 */
#[Internal(reason: 'Driver configuration; use QueueConfig for public access')]
final readonly class SqsDriverConfig
{
    public function __construct(
        public string $region = 'us-east-1',
        public string $key = '',
        public string $secret = '',
        public string $prefix = '',
    ) {}

    /**
     * @param array{
     *     region?: string,
     *     key?: string,
     *     secret?: string,
     *     prefix?: string,
     * } $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            region: $data['region'] ?? 'us-east-1',
            key: $data['key'] ?? '',
            secret: $data['secret'] ?? '',
            prefix: $data['prefix'] ?? '',
        );
    }
}
