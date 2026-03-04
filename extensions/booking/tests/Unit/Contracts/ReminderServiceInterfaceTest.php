<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Contracts;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Contracts\ReminderServiceInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;

#[CoversClass(ReminderServiceInterface::class)]
final class ReminderServiceInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanSendReminder(): void
    {
        $now = new DateTimeImmutable();
        $appointment = new Appointment(
            id: 'apt-1',
            bookingNumber: 'BK-2026-0001',
            serviceId: 'svc-1',
            customerId: 'cust-1',
            customerName: 'Jane',
            customerEmail: 'jane@example.com',
            customerPhone: '+1234567890',
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

        $stub = $this->createStub(ReminderServiceInterface::class);

        // sendReminder returns void -- no exception means success
        $stub->sendReminder($appointment);
        self::assertFalse($appointment->reminderSent);
    }

    #[Test]
    public function stubCanScheduleReminders(): void
    {
        $stub = $this->createStub(ReminderServiceInterface::class);
        $stub->method('scheduleReminders')->willReturn(5);

        self::assertSame(5, $stub->scheduleReminders());
    }

    #[Test]
    public function stubCanReturnZeroRemindersScheduled(): void
    {
        $stub = $this->createStub(ReminderServiceInterface::class);
        $stub->method('scheduleReminders')->willReturn(0);

        self::assertSame(0, $stub->scheduleReminders());
    }
}
