<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     max_batch_size?: int,
     *     max_queue_size?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            maxBatchSize: $data['max_batch_size'] ?? 512,
            maxQueueSize: $data['max_queue_size'] ?? 2048,
        );
    }
}
