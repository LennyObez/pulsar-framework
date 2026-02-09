<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Studio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Studio\Dto\MediaQueueEntry;
use Pulsar\Extension\Cms\Internal\Studio\MediaProcessingQueuePanel;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;

#[CoversClass(MediaProcessingQueuePanel::class)]
#[CoversClass(MediaQueueEntry::class)]
final class MediaProcessingQueuePanelTest extends TestCase
{
    #[Test]
    public function test_get_pending_returns_only_media_queue_jobs(): void
    {
        $mediaJob = new JobRecord(
            id: 'job-1',
            queue: 'cms_media_derivatives',
            jobClass: 'GenerateDerivativeJob',
            payload: '{"asset_id":"asset-1","variant":"thumb_480"}',
            attempts: 0,
            status: JobRecordStatus::Pending,
            createdAt: 1708300000,
            availableAt: 1708300000,
        );

        $otherJob = new JobRecord(
            id: 'job-2',
            queue: 'email',
            jobClass: 'SendEmailJob',
            payload: '{}',
            attempts: 0,
            status: JobRecordStatus::Pending,
            createdAt: 1708300000,
            availableAt: 1708300000,
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::once())
            ->method('findByStatus')
            ->with(JobRecordStatus::Pending)
            ->willReturn([$mediaJob, $otherJob]);

        $panel = new MediaProcessingQueuePanel($driver);

        $result = $panel->getPending();

        self::assertCount(1, $result);
        self::assertSame('job-1', $result[0]->jobId);
        self::assertSame('asset-1', $result[0]->mediaAssetId);
        self::assertSame('thumb_480', $result[0]->derivativeType);
        self::assertSame('pending', $result[0]->status);
    }

    #[Test]
    public function test_get_completed_maps_completed_at(): void
    {
        $job = new JobRecord(
            id: 'job-3',
            queue: 'cms_media_derivatives',
            jobClass: 'GenerateDerivativeJob',
            payload: '{"asset_id":"asset-2","variant":"medium_960","completed_at":1708301000}',
            attempts: 1,
            status: JobRecordStatus::Completed,
            createdAt: 1708300000,
            availableAt: 1708300000,
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::once())
            ->method('findByStatus')
            ->with(JobRecordStatus::Completed)
            ->willReturn([$job]);

        $panel = new MediaProcessingQueuePanel($driver);

        $result = $panel->getCompleted();

        self::assertCount(1, $result);
        self::assertSame(1708301000, $result[0]->completedAt);
        self::assertNull($result[0]->failedAt);
    }

    #[Test]
    public function test_get_failed_maps_failed_at(): void
    {
        $job = new JobRecord(
            id: 'job-4',
            queue: 'cms_media_derivatives',
            jobClass: 'GenerateDerivativeJob',
            payload: '{"asset_id":"asset-3","variant":"large_1920","failed_at":1708302000}',
            attempts: 3,
            status: JobRecordStatus::Failed,
            createdAt: 1708300000,
            availableAt: 1708300000,
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::once())
            ->method('findByStatus')
            ->with(JobRecordStatus::Failed)
            ->willReturn([$job]);

        $panel = new MediaProcessingQueuePanel($driver);

        $result = $panel->getFailed();

        self::assertCount(1, $result);
        self::assertSame(1708302000, $result[0]->failedAt);
        self::assertNull($result[0]->completedAt);
        self::assertSame('failed', $result[0]->status);
    }

    #[Test]
    public function test_pending_count_returns_queue_size(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::once())
            ->method('size')
            ->with('cms_media_derivatives')
            ->willReturn(7);

        $panel = new MediaProcessingQueuePanel($driver);

        self::assertSame(7, $panel->pendingCount());
    }

    #[Test]
    public function test_handles_empty_payload_gracefully(): void
    {
        $job = new JobRecord(
            id: 'job-5',
            queue: 'cms_media_derivatives',
            jobClass: 'GenerateDerivativeJob',
            payload: '',
            attempts: 0,
            status: JobRecordStatus::Pending,
            createdAt: 1708300000,
            availableAt: 1708300000,
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::once())
            ->method('findByStatus')
            ->with(JobRecordStatus::Pending)
            ->willReturn([$job]);

        $panel = new MediaProcessingQueuePanel($driver);

        $result = $panel->getPending();

        self::assertCount(1, $result);
        self::assertSame('', $result[0]->mediaAssetId);
        self::assertSame('', $result[0]->derivativeType);
    }

    #[Test]
    public function test_handles_invalid_json_payload_gracefully(): void
    {
        $job = new JobRecord(
            id: 'job-6',
            queue: 'cms_media_derivatives',
            jobClass: 'GenerateDerivativeJob',
            payload: '{invalid-json}',
            attempts: 0,
            status: JobRecordStatus::Pending,
            createdAt: 1708300000,
            availableAt: 1708300000,
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::once())
            ->method('findByStatus')
            ->with(JobRecordStatus::Pending)
            ->willReturn([$job]);

        $panel = new MediaProcessingQueuePanel($driver);

        $result = $panel->getPending();

        self::assertCount(1, $result);
        self::assertSame('', $result[0]->mediaAssetId);
    }

    #[Test]
    public function test_returns_empty_list_when_no_media_jobs(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('findByStatus')->willReturn([]);

        $panel = new MediaProcessingQueuePanel($driver);

        self::assertSame([], $panel->getPending());
        self::assertSame([], $panel->getCompleted());
        self::assertSame([], $panel->getFailed());
    }
}
