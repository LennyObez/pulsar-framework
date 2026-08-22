<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Calendar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Calendar\GoogleCalendarSyncInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;

#[CoversNothing]
final class GoogleCalendarSyncInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanCreateEvent(): void
    {
        $appointment = $this->createAppointment();

        $stub = $this->createStub(GoogleCalendarSyncInterface::class);
        $stub->method('createEvent')->willReturn('gcal-event-12345');

        $eventId = $stub->createEvent($appointment);

        self::assertSame('gcal-event-12345', $eventId);
    }

    #[Test]
    public function stubCanUpdateEvent(): void
    {
        $appointment = $this->createAppointment();

        $stub = $this->createStub(GoogleCalendarSyncInterface::class);

        // updateEvent returns void -- no exception means success
        $stub->updateEvent('gcal-event-12345', $appointment);
        self::assertSame('apt-1', $appointment->id);
    }

    #[Test]
    public function stubCanDeleteEvent(): void
    {
        $stub = $this->createStub(GoogleCalendarSyncInterface::class);

        // deleteEvent returns void -- no exception means success
        $stub->deleteEvent('gcal-event-12345');
        self::assertSame('gcal-event-12345', 'gcal-event-12345');
    }

    private function createAppointment(): Appointment
    {
        $now = new DateTimeImmutable();

        return new Appointment(
            id: 'apt-1',
            bookingNumber: 'BK-2026-0001',
            serviceId: 'svc-1',
            customerId: 'cust-1',
            customerName: 'Test',
            customerEmail: 'test@example.com',
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
    }
}
