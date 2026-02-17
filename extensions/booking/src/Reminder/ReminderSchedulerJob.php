<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Reminder;

use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Contracts\ReminderServiceInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;

use function sprintf;

/**
 * Scheduled job that finds appointments due for reminder and sends them.
 *
 * Runs every 15 minutes by default to check for upcoming appointments.
 */
#[Internal]
final readonly class ReminderSchedulerJob implements JobInterface
{
    public function __construct(
        private ReminderServiceInterface $reminderService,
    ) {}

    public function getName(): string
    {
        return 'booking:send-reminders';
    }

    public function getSchedule(): Schedule
    {
        return Schedule::everyMinutes(15);
    }

    public function getDescription(): string
    {
        return 'Send appointment reminders for upcoming bookings';
    }

    public function execute(JobContext $context): JobResult
    {
        $count = $this->reminderService->scheduleReminders();

        return JobResult::success(
            $this->getName(),
            $context->startedAt,
            sprintf('Sent %d reminder(s)', $count),
        );
    }
}
