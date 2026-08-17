<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Contracts;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Contracts\BookingServiceInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;

#[CoversNothing]
final class BookingServiceInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanRequestAppointment(): void
    {
        $appointment = $this->createAppointment(AppointmentStatus::Requested);

        $stub = $this->createStub(BookingServiceInterface::class);
        $stub->method('requestAppointment')->willReturn($appointment);

        $result = $stub->requestAppointment(
            'svc-1',
            'cust-1',
            'John',
            'john@example.com',
            '+1234567890',
            new DateTimeImmutable('+2 days'),
        );

        self::assertSame(AppointmentStatus::Requested, $result->status);
    }

    #[Test]
    public function stubCanConfirmAppointment(): void
    {
        $appointment = $this->createAppointment(AppointmentStatus::Confirmed);

        $stub = $this->createStub(BookingServiceInterface::class);
        $stub->method('confirm')->willReturn($appointment);

        self::assertSame(AppointmentStatus::Confirmed, $stub->confirm('apt-1')->status);
    }

    #[Test]
    public function stubCanCancelAppointment(): void
    {
        $appointment = $this->createAppointment(AppointmentStatus::Cancelled);

        $stub = $this->createStub(BookingServiceInterface::class);
        $stub->method('cancel')->willReturn($appointment);

        self::assertSame(AppointmentStatus::Cancelled, $stub->cancel('apt-1')->status);
    }

    #[Test]
    public function stubCanRescheduleAppointment(): void
    {
        $appointment = $this->createAppointment(AppointmentStatus::Rescheduled);

        $stub = $this->createStub(BookingServiceInterface::class);
        $stub->method('reschedule')->willReturn($appointment);

        $result = $stub->reschedule('apt-1', new DateTimeImmutable('+3 days'));
        self::assertSame(AppointmentStatus::Rescheduled, $result->status);
    }

    #[Test]
    public function stubCanMarkCompleted(): void
    {
        $appointment = $this->createAppointment(AppointmentStatus::Completed);

        $stub = $this->createStub(BookingServiceInterface::class);
        $stub->method('markCompleted')->willReturn($appointment);

        self::assertSame(AppointmentStatus::Completed, $stub->markCompleted('apt-1')->status);
    }

    #[Test]
    public function stubCanMarkNoShow(): void
    {
        $appointment = $this->createAppointment(AppointmentStatus::NoShow);

        $stub = $this->createStub(BookingServiceInterface::class);
        $stub->method('markNoShow')->willReturn($appointment);

        self::assertSame(AppointmentStatus::NoShow, $stub->markNoShow('apt-1')->status);
    }

    private function createAppointment(AppointmentStatus $status): Appointment
    {
        $now = new DateTimeImmutable();

        return new Appointment(
            id: 'apt-1',
            bookingNumber: 'BK-2026-0001',
            serviceId: 'svc-1',
            customerId: 'cust-1',
            customerName: 'John',
            customerEmail: 'john@example.com',
            customerPhone: '+1234567890',
            status: $status,
            scheduledAt: $now->modify('+2 days'),
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
