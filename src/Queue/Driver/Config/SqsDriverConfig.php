<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;

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
            region: Coerce::string($data['region'] ?? null, 'us-east-1'),
            key: Coerce::string($data['key'] ?? null),
            secret: Coerce::string($data['secret'] ?? null),
            prefix: Coerce::string($data['prefix'] ?? null),
        );
    }

    /**
     * Prevent credentials from leaking in debug output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'region' => $this->region,
            'key' => $this->key !== '' ? '[REDACTED]' : '',
            'secret' => $this->secret !== '' ? '[REDACTED]' : '',
            'prefix' => $this->prefix,
        ];
    }
}
