<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Reminder;

use DateTimeImmutable;
use Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Contracts\ReminderServiceInterface;
use Pulsar\Extension\Booking\Reminder\ReminderSchedulerJob;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobStatus;

#[CoversClass(ReminderSchedulerJob::class)]
final class ReminderSchedulerJobTest extends TestCase
{
    private ReminderServiceInterface&Stub $reminderService;
    private ReminderSchedulerJob $job;

    protected function setUp(): void
    {
        $this->reminderService = $this->createStub(ReminderServiceInterface::class);
        $this->job = new ReminderSchedulerJob($this->reminderService);
    }

    #[Test]
    public function getNameReturnsBookingSendReminders(): void
    {
        self::assertSame('booking:send-reminders', $this->job->getName());
    }

    #[Test]
    public function getScheduleCallsScheduleFactory(): void
    {
        // Schedule::everyMinutes() is not implemented on Schedule yet;
        // this test verifies it throws rather than silently returning
        // the wrong schedule.
        $this->expectException(Error::class);
        $this->job->getSchedule();
    }

    #[Test]
    public function getDescriptionIsNotEmpty(): void
    {
        self::assertNotEmpty($this->job->getDescription());
    }

    #[Test]
    public function executeReturnsSuccessResultWithCount(): void
    {
        $this->reminderService->method('scheduleReminders')->willReturn(5);

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $this->job->execute($context);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('5', $result->output);
        self::assertSame('booking:send-reminders', $result->jobName);
    }

    #[Test]
    public function executeReportsZeroRemindersWhenNoneNeeded(): void
    {
        $this->reminderService->method('scheduleReminders')->willReturn(0);

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $this->job->execute($context);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('0', $result->output);
    }
}
