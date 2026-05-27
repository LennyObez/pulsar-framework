<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;
use SensitiveParameter;

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
            region: Coerce::string($data['region'] ?? null, 'us-east-1'),
            accessKey: Coerce::string($data['access_key'] ?? null),
            secretKey: Coerce::string($data['secret_key'] ?? null),
            endpoint: Coerce::nullableString($data['endpoint'] ?? null),
        );
    }
}
