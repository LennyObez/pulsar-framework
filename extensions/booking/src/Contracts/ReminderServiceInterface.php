<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Booking\Domain\Appointment;

/**
 * Orchestrates appointment reminders via email and SMS.
 */
#[Api(since: '1.0.0')]
interface ReminderServiceInterface
{
    /**
     * Send a reminder for the given appointment.
     *
     * Dispatches email and/or SMS based on configuration.
     */
    public function sendReminder(Appointment $appointment): void;

    /**
     * Schedule reminders for all upcoming appointments that need them.
     *
     * @return int Number of reminders sent
     */
    public function scheduleReminders(): int;
}
