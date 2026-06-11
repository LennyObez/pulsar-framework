<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Batch exporter configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BatchConfig
{
    public function __construct(
        public int $maxBatchSize = 512,
        public int $maxQueueSize = 2048,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            maxBatchSize: Coerce::int($data['max_batch_size'] ?? null, 512),
            maxQueueSize: Coerce::int($data['max_queue_size'] ?? null, 2048),
        );
    }
}
