<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Studio;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Internal\Studio\Dto\MediaQueueEntry;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Studio panel data provider for the media processing queue.
 *
 * Shows pending and completed derivative generation jobs from the
 * queue system, including job status, media asset reference,
 * derivative type, and timing information.
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
            $mediaAssetId = $payload['asset_id'] ?? '';
            $derivativeType = $payload['variant'] ?? '';

            $completedAt = null;
            $failedAt = null;

            if ($record->status === JobRecordStatus::Completed) {
                $completedAt = $payload['completed_at'] ?? null;
            }

            if ($record->status === JobRecordStatus::Failed) {
                $failedAt = $payload['failed_at'] ?? null;
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
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException) {
            return [];
        }
    }
}
