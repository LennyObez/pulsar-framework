<?php

declare(strict_types=1);

namespace Pulsar\Cloud;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_float;
use function is_int;
use function is_string;

/**
 * Top-level cloud configuration for provider selection and shared settings.
 */
#[Api(since: '1.0.0')]
final readonly class CloudConfig
{
    /**
     * @param string                $defaultProvider The default cloud provider ("aws", "gcp", "azure")
     * @param array<string, mixed>  $aws             AWS-specific config
     * @param array<string, mixed>  $gcp             GCP-specific config
     * @param array<string, mixed>  $azure           Azure-specific config
     * @param int                   $httpTimeout     Default HTTP request timeout in seconds
     * @param int                   $retryAttempts   Number of retry attempts for transient failures
     * @param float                 $retryDelay      Base delay between retries in seconds
     */
    public function __construct(
        public string $defaultProvider = 'aws',
        public array $aws = [],
        public array $gcp = [],
        public array $azure = [],
        public int $httpTimeout = 30,
        public int $retryAttempts = 3,
        public float $retryDelay = 0.5,
    ) {}

    /**
     * @param array<string, mixed> $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawProvider = $data['default_provider'] ?? 'aws';
        $rawAws = $data['aws'] ?? [];
        $rawGcp = $data['gcp'] ?? [];
        $rawAzure = $data['azure'] ?? [];
        $rawTimeout = $data['http_timeout'] ?? 30;
        $rawRetryAttempts = $data['retry_attempts'] ?? 3;
        $rawRetryDelay = $data['retry_delay'] ?? 0.5;

        /** @var array<string, mixed> $awsArray */
        $awsArray = is_array($rawAws) ? $rawAws : [];
        /** @var array<string, mixed> $gcpArray */
        $gcpArray = is_array($rawGcp) ? $rawGcp : [];
        /** @var array<string, mixed> $azureArray */
        $azureArray = is_array($rawAzure) ? $rawAzure : [];

        return new self(
            defaultProvider: is_string($rawProvider) ? $rawProvider : 'aws',
            aws: $awsArray,
            gcp: $gcpArray,
            azure: $azureArray,
            httpTimeout: is_int($rawTimeout) ? $rawTimeout : 30,
            retryAttempts: is_int($rawRetryAttempts) ? $rawRetryAttempts : 3,
            retryDelay: is_float($rawRetryDelay) || is_int($rawRetryDelay) ? (float) $rawRetryDelay : 0.5,
        );
    }
}
