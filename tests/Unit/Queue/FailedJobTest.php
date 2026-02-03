<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\FailedJob;

#[CoversClass(FailedJob::class)]
final class FailedJobTest extends TestCase
{
    #[Test]
    public function it_stores_all_properties(): void
    {
        $failedJob = new FailedJob(
            id: 'failed-001',
            queue: 'notifications',
            jobClass: 'App\\Jobs\\SendNotification',
            payload: '{"user_id":42}',
            exception: 'Connection timed out',
            failedAt: 1700000500,
            attempts: 3,
        );

        self::assertSame('failed-001', $failedJob->id);
        self::assertSame('notifications', $failedJob->queue);
        self::assertSame('App\\Jobs\\SendNotification', $failedJob->jobClass);
        self::assertSame('{"user_id":42}', $failedJob->payload);
        self::assertSame('Connection timed out', $failedJob->exception);
        self::assertSame(1700000500, $failedJob->failedAt);
        self::assertSame(3, $failedJob->attempts);
    }

    #[Test]
    public function it_accepts_single_attempt(): void
    {
        $failedJob = new FailedJob(
            id: 'failed-002',
            queue: 'default',
            jobClass: 'App\\Jobs\\Noop',
            payload: '{}',
            exception: 'Immediate failure',
            failedAt: 1700000000,
            attempts: 1,
        );

        self::assertSame(1, $failedJob->attempts);
    }

    #[Test]
    public function it_stores_multiline_exception_message(): void
    {
        $exception = "RuntimeException: Something went wrong\n"
            . "at /app/src/Jobs/SendEmail.php:42\n"
            . 'at /app/vendor/pulsar/queue/Worker.php:100';

        $failedJob = new FailedJob(
            id: 'failed-003',
            queue: 'emails',
            jobClass: 'App\\Jobs\\SendEmail',
            payload: '{}',
            exception: $exception,
            failedAt: 1700000000,
            attempts: 2,
        );

        self::assertSame($exception, $failedJob->exception);
    }
}
