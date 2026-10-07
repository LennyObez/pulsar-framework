<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Reminder;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;
use Pulsar\Extension\Booking\Reminder\AppointmentReminderMailable;
use Pulsar\Extension\Booking\Reminder\EmailReminderSender;
use Pulsar\Mail\MailManagerInterface;

#[CoversClass(EmailReminderSender::class)]
#[CoversClass(AppointmentReminderMailable::class)]
final class EmailReminderSenderTest extends TestCase
{
    #[Test]
    public function sendDelegatesMailableToMailManager(): void
    {
        $mailManager = $this->createMock(MailManagerInterface::class);
        $mailManager->expects(self::once())->method('send');

        $sender = new EmailReminderSender($mailManager);
        $sender->send($this->makeAppointment());
    }

    #[Test]
    public function mailableEnvelopeContainsBookingNumber(): void
    {
        $appointment = $this->makeAppointment();
        $mailable = new AppointmentReminderMailable($appointment);

        $envelope = $mailable->envelope();

        self::assertStringContainsString('BK-001', $envelope->subject);
    }

    #[Test]
    public function mailableContentContainsCustomerNameAndDuration(): void
    {
        $appointment = $this->makeAppointment();
        $mailable = new AppointmentReminderMailable($appointment);

        $content = $mailable->content();

        self::assertStringContainsString('Test User', $content->html);
        self::assertStringContainsString('60 minutes', $content->html);
        self::assertStringContainsString('Test User', $content->text);
        self::assertStringContainsString('60 minutes', $content->text);
    }

    #[Test]
    public function mailableContentContainsBookingNumberInBothFormats(): void
    {
        $appointment = $this->makeAppointment();
        $mailable = new AppointmentReminderMailable($appointment);

        $content = $mailable->content();

        self::assertStringContainsString('BK-001', $content->html);
        self::assertStringContainsString('BK-001', $content->text);
    }

    private function makeAppointment(): Appointment
    {
        $now = new DateTimeImmutable();

        return new Appointment(
            id: 'appt-1',
            bookingNumber: 'BK-001',
            serviceId: 'svc-1',
            customerId: 'cust-1',
            customerName: 'Test User',
            customerEmail: 'test@example.com',
            customerPhone: '+15551234567',
            status: AppointmentStatus::Confirmed,
            scheduledAt: $now->modify('+1 day'),
            duration: 60,
            depositAmount: null,
            depositPaid: false,
            notes: '',
            reminderSent: false,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
