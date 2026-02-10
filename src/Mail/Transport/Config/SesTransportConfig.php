<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use SensitiveParameter;

use function is_string;

/**
 * Configuration for the AWS SES mail transport.
 */
#[Internal]
final readonly class SesTransportConfig
{
    public function __construct(
        public string $region = 'us-east-1',
        public string $accessKey = '',
        #[SensitiveParameter]
        public string $secretKey = '',
        public ?string $endpoint = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            region: is_string($data['region'] ?? null) ? $data['region'] : 'us-east-1',
            accessKey: is_string($data['access_key'] ?? null) ? $data['access_key'] : '',
            secretKey: is_string($data['secret_key'] ?? null) ? $data['secret_key'] : '',
            endpoint: is_string($data['endpoint'] ?? null) ? $data['endpoint'] : null,
        );
    }
}
