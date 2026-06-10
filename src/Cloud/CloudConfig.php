<?php

declare(strict_types=1);

namespace Pulsar\Cloud;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;

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
        $aws = $data['aws'] ?? null;
        $gcp = $data['gcp'] ?? null;
        $azure = $data['azure'] ?? null;

        return new self(
            defaultProvider: Coerce::string($data['default_provider'] ?? null, 'aws'),
            aws: is_array($aws) ? $aws : [],
            gcp: is_array($gcp) ? $gcp : [],
            azure: is_array($azure) ? $azure : [],
            httpTimeout: Coerce::int($data['http_timeout'] ?? null, 30),
            retryAttempts: Coerce::int($data['retry_attempts'] ?? null, 3),
            retryDelay: Coerce::float($data['retry_delay'] ?? null, 0.5),
        );
    }
}
