<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;

#[CoversClass(JobRecord::class)]
final class JobRecordTest extends TestCase
{
    #[Test]
    public function it_stores_all_properties(): void
    {
        $record = new JobRecord(
            id: 'job-123',
            queue: 'emails',
            jobClass: 'App\\Jobs\\SendEmail',
            payload: '{"to":"user@example.com"}',
            attempts: 2,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000010,
        );

        self::assertSame('job-123', $record->id);
        self::assertSame('emails', $record->queue);
        self::assertSame('App\\Jobs\\SendEmail', $record->jobClass);
        self::assertSame('{"to":"user@example.com"}', $record->payload);
        self::assertSame(2, $record->attempts);
        self::assertSame(JobRecordStatus::Processing, $record->status);
        self::assertSame(1700000000, $record->createdAt);
        self::assertSame(1700000010, $record->availableAt);
    }

    #[Test]
    public function it_accepts_pending_status(): void
    {
        $record = new JobRecord(
            id: 'job-1',
            queue: 'default',
            jobClass: 'App\\Jobs\\Noop',
            payload: '{}',
            attempts: 0,
            status: JobRecordStatus::Pending,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        self::assertSame(JobRecordStatus::Pending, $record->status);
    }

    #[Test]
    public function it_accepts_completed_status(): void
    {
        $record = new JobRecord(
            id: 'job-2',
            queue: 'default',
            jobClass: 'App\\Jobs\\Noop',
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Completed,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        self::assertSame(JobRecordStatus::Completed, $record->status);
    }

    #[Test]
    public function it_accepts_failed_status(): void
    {
        $record = new JobRecord(
            id: 'job-3',
            queue: 'default',
            jobClass: 'App\\Jobs\\Noop',
            payload: '{}',
            attempts: 3,
            status: JobRecordStatus::Failed,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        self::assertSame(JobRecordStatus::Failed, $record->status);
    }

    #[Test]
    public function it_accepts_dead_lettered_status(): void
    {
        $record = new JobRecord(
            id: 'job-4',
            queue: 'default',
            jobClass: 'App\\Jobs\\Noop',
            payload: '{}',
            attempts: 5,
            status: JobRecordStatus::DeadLettered,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        self::assertSame(JobRecordStatus::DeadLettered, $record->status);
    }

    #[Test]
    public function it_accepts_zero_attempts(): void
    {
        $record = new JobRecord(
            id: 'job-new',
            queue: 'default',
            jobClass: 'App\\Jobs\\Noop',
            payload: '{}',
            attempts: 0,
            status: JobRecordStatus::Pending,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        self::assertSame(0, $record->attempts);
    }
}
