<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;

/**
 * Batch exporter configuration.
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
        $rawMaxBatchSize = $data['max_batch_size'] ?? 512;
        $rawMaxQueueSize = $data['max_queue_size'] ?? 2048;

        return new self(
            maxBatchSize: is_int($rawMaxBatchSize) ? $rawMaxBatchSize : 512,
            maxQueueSize: is_int($rawMaxQueueSize) ? $rawMaxQueueSize : 2048,
        );
    }
}
