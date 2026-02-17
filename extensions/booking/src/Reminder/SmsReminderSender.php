<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Reminder;

use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Domain\Appointment;

/**
 * Sends appointment reminder SMS messages.
 */
#[Internal]
final readonly class SmsReminderSender
{
    public function __construct(
        private SmsProviderInterface $smsProvider,
    ) {}

    /**
     * Send a reminder SMS for the given appointment.
     */
    public function send(Appointment $appointment): void
    {
        $scheduledAt = $appointment->scheduledAt->format('M j, Y g:i A');

        $body = "Reminder: Your appointment {$appointment->bookingNumber} is on {$scheduledAt}. "
            . "Duration: {$appointment->duration} min. "
            . 'Contact us to reschedule or cancel.';

        $this->smsProvider->send($appointment->customerPhone, $body);
    }
}
