<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Studio;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Internal\Studio\Dto\MediaQueueEntry;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;

use function is_int;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Studio panel data provider for the media processing queue.
 *
 * Shows pending and completed derivative generation jobs from the
 * queue system, including job status, media asset reference,
 * derivative type, and timing information.
 *
 * @psalm-api Resolved from the DI container by CmsStudioModule; not
 *            instantiated by name.
 */
#[Internal]
final readonly class MediaProcessingQueuePanel
{
    /** Queue name for media derivative processing jobs. */
    private const string MEDIA_QUEUE = 'cms_media_derivatives';

    public function __construct(
        private QueueDriverInterface $queueDriver,
    ) {}

    /**
     * Get all pending media processing jobs.
     *
     * @return list<MediaQueueEntry>
     */
    public function getPending(): array
    {
        return $this->mapToEntries(
            $this->queueDriver->findByStatus(JobRecordStatus::Pending),
        );
    }

    /**
     * Get all completed media processing jobs.
     *
     * @return list<MediaQueueEntry>
     */
    public function getCompleted(): array
    {
        return $this->mapToEntries(
            $this->queueDriver->findByStatus(JobRecordStatus::Completed),
        );
    }

    /**
     * Get all failed media processing jobs.
     *
     * @return list<MediaQueueEntry>
     */
    public function getFailed(): array
    {
        return $this->mapToEntries(
            $this->queueDriver->findByStatus(JobRecordStatus::Failed),
        );
    }

    /**
     * Get the count of pending jobs in the media queue.
     */
    public function pendingCount(): int
    {
        return $this->queueDriver->size(self::MEDIA_QUEUE);
    }

    /**
     * Map job records to media queue entries, filtering to media-related jobs only.
     *
     * @param list<JobRecord> $records
     *
     * @return list<MediaQueueEntry>
     */
    private function mapToEntries(array $records): array
    {
        $entries = [];

        foreach ($records as $record) {
            if ($record->queue !== self::MEDIA_QUEUE) {
                continue;
            }

            $payload = $this->decodePayload($record->payload);
            /** @var mixed $rawAssetId */
            $rawAssetId = $payload['asset_id'] ?? null;
            /** @var mixed $rawVariant */
            $rawVariant = $payload['variant'] ?? null;
            $mediaAssetId = is_string($rawAssetId) ? $rawAssetId : '';
            $derivativeType = is_string($rawVariant) ? $rawVariant : '';

            $completedAt = null;
            $failedAt = null;

            if ($record->status === JobRecordStatus::Completed) {
                /** @var mixed $rawCompleted */
                $rawCompleted = $payload['completed_at'] ?? null;
                $completedAt = is_int($rawCompleted) ? $rawCompleted : null;
            }

            if ($record->status === JobRecordStatus::Failed) {
                /** @var mixed $rawFailed */
                $rawFailed = $payload['failed_at'] ?? null;
                $failedAt = is_int($rawFailed) ? $rawFailed : null;
            }

            $entries[] = new MediaQueueEntry(
                jobId: $record->id,
                status: $record->status->value,
                jobClass: $record->jobClass,
                mediaAssetId: $mediaAssetId,
                derivativeType: $derivativeType,
                createdAt: $record->createdAt,
                completedAt: $completedAt,
                failedAt: $failedAt,
            );
        }

        return $entries;
    }

    /**
     * Safely decode a job's JSON payload.
     *
     * @return array<string, mixed>
     */
    private function decodePayload(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException) {
            return [];
        }
    }
}
