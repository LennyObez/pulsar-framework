<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Studio\Dto;

use Pulsar\Api\Internal;

/**
 * Row in the media processing queue panel: a single derivative generation job.
 */
#[Internal]
final readonly class MediaQueueEntry
{
    public function __construct(
        public string $jobId,
        public string $status,
        public string $jobClass,
        public string $mediaAssetId,
        public string $derivativeType,
        public int $createdAt,
        public ?int $completedAt,
        public ?int $failedAt,
    ) {}
}
