<?php

declare(strict_types=1);

namespace Pulsar\Cloud;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Top-level cloud configuration for provider selection and shared settings.
 * @api
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
     * @param array{
     *     default_provider?: string,
     *     aws?: array<string, mixed>,
     *     gcp?: array<string, mixed>,
     *     azure?: array<string, mixed>,
     *     http_timeout?: int,
     *     retry_attempts?: int,
     *     retry_delay?: float|int,
     * } $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            defaultProvider: $data['default_provider'] ?? 'aws',
            aws: $data['aws'] ?? [],
            gcp: $data['gcp'] ?? [],
            azure: $data['azure'] ?? [],
            httpTimeout: $data['http_timeout'] ?? 30,
            retryAttempts: $data['retry_attempts'] ?? 3,
            retryDelay: (float) ($data['retry_delay'] ?? 0.5),
        );
    }
}
