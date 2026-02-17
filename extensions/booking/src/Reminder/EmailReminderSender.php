<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Reminder;

use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Mail\Content;
use Pulsar\Mail\Envelope;
use Pulsar\Mail\Mailable;
use Pulsar\Mail\MailManagerInterface;

/**
 * Sends appointment reminder emails.
 */
#[Internal]
final readonly class EmailReminderSender
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

/**
 * Mailable for appointment reminders.
 */
final class AppointmentReminderMailable extends Mailable
{
    public function __construct(
        private readonly Appointment $appointment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reminder: Your appointment {$this->appointment->bookingNumber} is coming up",
        );
    }

    public function content(): Content
    {
        $scheduledAt = $this->appointment->scheduledAt->format('l, F j, Y \a\t g:i A');

        $html = <<<HTML
            <h2>Appointment Reminder</h2>
            <p>Hello {$this->appointment->customerName},</p>
            <p>This is a reminder that your appointment <strong>{$this->appointment->bookingNumber}</strong> is scheduled for:</p>
            <p><strong>{$scheduledAt}</strong></p>
            <p>Duration: {$this->appointment->duration} minutes</p>
            <p>If you need to reschedule or cancel, please contact us as soon as possible.</p>
            HTML;

        $text = "Appointment Reminder\n\n"
            . "Hello {$this->appointment->customerName},\n\n"
            . "This is a reminder that your appointment {$this->appointment->bookingNumber} is scheduled for:\n"
            . "{$scheduledAt}\n"
            . "Duration: {$this->appointment->duration} minutes\n\n"
            . 'If you need to reschedule or cancel, please contact us as soon as possible.';

        return new Content(html: $html, text: $text);
    }
}
