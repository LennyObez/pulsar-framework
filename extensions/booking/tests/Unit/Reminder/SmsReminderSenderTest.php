<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Reminder;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;
use Pulsar\Extension\Booking\Reminder\SmsProviderInterface;
use Pulsar\Extension\Booking\Reminder\SmsReminderSender;

#[CoversClass(SmsReminderSender::class)]
final class SmsReminderSenderTest extends TestCase
{
    #[Test]
    public function sendDelegatesToSmsProvider(): void
    {
        $provider = $this->createMock(SmsProviderInterface::class);
        $provider->expects(self::once())
            ->method('send')
            ->with('+15551234567', self::callback(function (string $body): bool {
                return str_contains($body, 'BK-001')
                    && str_contains($body, '60 min');
            }));

        $sender = new SmsReminderSender($provider);
        $sender->send($this->makeAppointment());
    }

    #[Test]
    public function sendIncludesBookingNumberInBody(): void
    {
        $capturedBody = '';

        $provider = $this->createMock(SmsProviderInterface::class);
        $provider->expects(self::once())
            ->method('send')
            ->willReturnCallback(function (string $to, string $body) use (&$capturedBody): void {
                $capturedBody = $body;
            });

        $sender = new SmsReminderSender($provider);
        $sender->send($this->makeAppointment());

        self::assertStringContainsString('BK-001', $capturedBody);
    }

    #[Test]
    public function sendUsesCorrectPhoneNumber(): void
    {
        $capturedTo = '';

        $provider = $this->createMock(SmsProviderInterface::class);
        $provider->expects(self::once())
            ->method('send')
            ->willReturnCallback(function (string $to, string $body) use (&$capturedTo): void {
                $capturedTo = $to;
            });

        $sender = new SmsReminderSender($provider);
        $sender->send($this->makeAppointment());

        self::assertSame('+15551234567', $capturedTo);
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
