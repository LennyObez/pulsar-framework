<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use SensitiveParameter;

/**
 * Configuration for the AWS SES mail transport.
 */
#[Internal]
final readonly class SesTransportConfig
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $region = 'us-east-1',
        public string $accessKey = '',
        #[SensitiveParameter]
        public string $secretKey = '',
        public ?string $endpoint = null,
    ) {}

    /**
     * @param array{
     *     region?: string,
     *     access_key?: string,
     *     secret_key?: string,
     *     endpoint?: string|null,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            region: $data['region'] ?? 'us-east-1',
            accessKey: $data['access_key'] ?? '',
            secretKey: $data['secret_key'] ?? '',
            endpoint: $data['endpoint'] ?? null,
        );
    }
}
