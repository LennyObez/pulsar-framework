<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Aws\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use SensitiveParameter;

/**
 * Configuration DTO for AWS cloud services.
 */
#[Internal]
final readonly class AwsConfig
{
    public function __construct(
        public string $region = 'us-east-1',
        public string $accessKey = '',
        #[SensitiveParameter]
        public string $secretKey = '',
        public ?string $endpoint = null,
        public ?string $sessionToken = null,
    ) {}

    /**
     * @param array{
     *     region?: string,
     *     access_key?: string,
     *     secret_key?: string,
     *     endpoint?: string|null,
     *     session_token?: string|null,
     * } $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            region: $data['region'] ?? 'us-east-1',
            accessKey: $data['access_key'] ?? '',
            secretKey: $data['secret_key'] ?? '',
            endpoint: $data['endpoint'] ?? null,
            sessionToken: $data['session_token'] ?? null,
        );
    }

    /**
     * Resolve credentials from config or environment variables.
     *
     * @return array{access_key: string, secret_key: string}
     */
    #[NoDiscard]
    public function resolveCredentials(): array
    {
        $accessKey = $this->accessKey !== '' ? $this->accessKey : (getenv('AWS_ACCESS_KEY_ID') ?: '');
        $secretKey = $this->secretKey !== '' ? $this->secretKey : (getenv('AWS_SECRET_ACCESS_KEY') ?: '');

        return [
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
        ];
    }

    /**
     * Prevent secrets from leaking in debug output.
     *
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'region' => $this->region,
            'accessKey' => '[REDACTED]',
            'secretKey' => '[REDACTED]',
            'endpoint' => $this->endpoint,
            'sessionToken' => $this->sessionToken !== null ? '[REDACTED]' : null,
        ];
    }
}
