<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Reminder;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Contracts\ReminderServiceInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\BookingConfig;
use Throwable;

/**
 * Orchestrates appointment reminders via email and SMS.
 */
#[Internal]
final readonly class ReminderService implements ReminderServiceInterface
{
    public function __construct(
        private AppointmentRepositoryInterface $repository,
        private BookingConfig $config,
        private EmailReminderSender $emailSender,
        private ?SmsReminderSender $smsSender,
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function sendReminder(Appointment $appointment): void
    {
        if ($appointment->reminderSent) {
            return;
        }

        if ($this->config->emailReminderEnabled) {
            try {
                $this->emailSender->send($appointment);
            } catch (Throwable $e) {
                $this->logger->error('Failed to send email reminder', [
                    'booking_number' => $appointment->bookingNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($this->config->smsReminderEnabled && $this->smsSender !== null && $appointment->customerPhone !== '') {
            try {
                $this->smsSender->send($appointment);
            } catch (Throwable $e) {
                $this->logger->error('Failed to send SMS reminder', [
                    'booking_number' => $appointment->bookingNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $updated = $appointment->markReminderSent();
        $this->repository->save($updated);

        $this->logger->info('Reminder sent', [
            'booking_number' => $appointment->bookingNumber,
        ]);
    }

    #[Override]
    public function scheduleReminders(): int
    {
        $appointments = $this->repository->findNeedingReminder(
            $this->config->reminderHoursBefore,
        );

        $count = 0;

        foreach ($appointments as $appointment) {
            $this->sendReminder($appointment);
            $count++;
        }

        return $count;
    }
}
