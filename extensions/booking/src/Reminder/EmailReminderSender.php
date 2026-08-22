<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Reminder;

use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Contracts\ReminderSenderInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Mail\MailManagerInterface;

/**
 * Sends appointment reminder emails.
 */
#[Internal]
final readonly class EmailReminderSender implements ReminderSenderInterface
{
    public function __construct(
        private MailManagerInterface $mailManager,
    ) {}

    /**
     * Send a reminder email for the given appointment.
     */
    public function send(Appointment $appointment): void
    {
        $mailable = new AppointmentReminderMailable($appointment);
        $mailable->to($appointment->customerEmail, $appointment->customerName);

        $this->mailManager->send($mailable);
    }
}
