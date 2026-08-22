<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Contracts;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;

#[CoversNothing]
final class AppointmentRepositoryInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanReturnAppointmentById(): void
    {
        $appointment = $this->createAppointment();

        $stub = $this->createStub(AppointmentRepositoryInterface::class);
        $stub->method('findById')->willReturn($appointment);

        self::assertSame($appointment, $stub->findById('apt-1'));
    }

    #[Test]
    public function stubCanReturnNullById(): void
    {
        $stub = $this->createStub(AppointmentRepositoryInterface::class);
        $stub->method('findById')->willReturn(null);

        self::assertNull($stub->findById('nonexistent'));
    }

    #[Test]
    public function stubCanReturnAppointmentByBookingNumber(): void
    {
        $appointment = $this->createAppointment();

        $stub = $this->createStub(AppointmentRepositoryInterface::class);
        $stub->method('findByBookingNumber')->willReturn($appointment);

        self::assertSame('BK-2026-0001', $stub->findByBookingNumber('BK-2026-0001')->bookingNumber);
    }

    #[Test]
    public function stubCanReturnAppointmentsByDate(): void
    {
        $appointment = $this->createAppointment();

        $stub = $this->createStub(AppointmentRepositoryInterface::class);
        $stub->method('findByDate')->willReturn([$appointment]);

        self::assertCount(1, $stub->findByDate(new DateTimeImmutable('2026-04-01')));
    }

    #[Test]
    public function stubCanReturnAppointmentsByCustomer(): void
    {
        $stub = $this->createStub(AppointmentRepositoryInterface::class);
        $stub->method('findByCustomer')->willReturn([]);

        self::assertCount(0, $stub->findByCustomer('cust-none'));
    }

    #[Test]
    public function stubCanReturnUpcomingAppointments(): void
    {
        $appointment = $this->createAppointment();

        $stub = $this->createStub(AppointmentRepositoryInterface::class);
        $stub->method('findUpcoming')->willReturn([$appointment]);

        self::assertCount(1, $stub->findUpcoming(10));
    }

    #[Test]
    public function stubCanReturnCountByStatus(): void
    {
        $stub = $this->createStub(AppointmentRepositoryInterface::class);
        $stub->method('countByStatus')->willReturn(['confirmed' => 3, 'completed' => 5]);

        $counts = $stub->countByStatus(new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-31'));
        self::assertSame(3, $counts['confirmed']);
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
