<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Internal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Contracts\TimeSlotManagerInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;
use Pulsar\Extension\Booking\Domain\BookingConfig;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Extension\Booking\Internal\BookingNumberGenerator;
use Pulsar\Extension\Booking\Internal\BookingService;

#[CoversClass(BookingService::class)]
final class BookingServiceTest extends TestCase
{
    private AppointmentRepositoryInterface&Stub $repository;
    private TimeSlotManagerInterface&Stub $timeSlotManager;
    private BookingService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(AppointmentRepositoryInterface::class);
        $this->timeSlotManager = $this->createStub(TimeSlotManagerInterface::class);

        $config = BookingConfig::fromArray([
            'min_advance_hours' => 2,
            'max_advance_days' => 90,
            'cancellation_policy_hours' => 4,
        ]);

        $this->service = new BookingService(
            $this->repository,
            new BookingNumberGenerator(),
            $config,
            new NullLogger(),
        );
    }

    public function testRequestAppointmentCreatesWithRequestedStatus(): void
    {
        $scheduledAt = new DateTimeImmutable('+3 days');

        $appointment = $this->service->requestAppointment(
            serviceId: 'svc-001',
            customerId: 'cust-001',
            customerName: 'Jane Doe',
            customerEmail: 'jane@example.com',
            customerPhone: '+1234567890',
            scheduledAt: $scheduledAt,
            notes: 'First visit',
        );

        self::assertSame(AppointmentStatus::Requested, $appointment->status);
        self::assertSame('svc-001', $appointment->serviceId);
        self::assertSame('Jane Doe', $appointment->customerName);
        self::assertStringStartsWith('BKG-', $appointment->bookingNumber);
    }

    public function testRequestAppointmentTooSoonThrows(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('at least 2 hours');

        $this->service->requestAppointment(
            serviceId: 'svc-001',
            customerId: 'cust-001',
            customerName: 'Jane Doe',
            customerEmail: 'jane@example.com',
            customerPhone: '+1234567890',
            scheduledAt: new DateTimeImmutable('+30 minutes'),
        );
    }

    public function testRequestAppointmentTooFarAheadThrows(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('more than 90 days');

        $this->service->requestAppointment(
            serviceId: 'svc-001',
            customerId: 'cust-001',
            customerName: 'Jane Doe',
            customerEmail: 'jane@example.com',
            customerPhone: '+1234567890',
            scheduledAt: new DateTimeImmutable('+100 days'),
        );
    }

    public function testConfirmTransitionsToConfirmed(): void
    {
        $appointment = $this->makeAppointment(AppointmentStatus::Requested);
        $this->repository->method('findById')->willReturn($appointment);

        $confirmed = $this->service->confirm('apt-001');

        self::assertSame(AppointmentStatus::Confirmed, $confirmed->status);
    }

    public function testCancelTransitionsToCancelled(): void
    {
        $appointment = $this->makeAppointment(
            AppointmentStatus::Requested,
            new DateTimeImmutable('+5 days'),
        );
        $this->repository->method('findById')->willReturn($appointment);

        $cancelled = $this->service->cancel('apt-001');

        self::assertSame(AppointmentStatus::Cancelled, $cancelled->status);
    }

    public function testCancelTooLateThrows(): void
    {
        $appointment = $this->makeAppointment(
            AppointmentStatus::Confirmed,
            new DateTimeImmutable('+1 hour'),
        );
        $this->repository->method('findById')->willReturn($appointment);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('at least 4 hours');

        $this->service->cancel('apt-001');
    }

    public function testReschedule(): void
    {
        $appointment = $this->makeAppointment(
            AppointmentStatus::Confirmed,
            new DateTimeImmutable('+5 days'),
        );
        $this->repository->method('findById')->willReturn($appointment);

        $newDate = new DateTimeImmutable('+7 days');
        $rescheduled = $this->service->reschedule('apt-001', $newDate);

        self::assertSame(AppointmentStatus::Rescheduled, $rescheduled->status);
    }

    public function testMarkCompleted(): void
    {
        $appointment = $this->makeAppointment(AppointmentStatus::InProgress);
        $this->repository->method('findById')->willReturn($appointment);

        $completed = $this->service->markCompleted('apt-001');

        self::assertSame(AppointmentStatus::Completed, $completed->status);
    }

    public function testMarkNoShow(): void
    {
        $appointment = $this->makeAppointment(AppointmentStatus::InProgress);
        $this->repository->method('findById')->willReturn($appointment);

        $noShow = $this->service->markNoShow('apt-001');

        self::assertSame(AppointmentStatus::NoShow, $noShow->status);
    }

    public function testConfirmNonExistentThrows(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('not found');

        $this->service->confirm('nonexistent');
    }

    private function makeAppointment(
        AppointmentStatus $status,
        ?DateTimeImmutable $scheduledAt = null,
    ): Appointment {
        return new Appointment(
            id: 'apt-001',
            bookingNumber: 'BKG-2026-000001',
            serviceId: 'svc-001',
            customerId: 'cust-001',
            customerName: 'Jane Doe',
            customerEmail: 'jane@example.com',
            customerPhone: '+1234567890',
            status: $status,
            scheduledAt: $scheduledAt ?? new DateTimeImmutable('+5 days'),
            duration: 60,
            depositAmount: null,
            depositPaid: false,
            notes: '',
            reminderSent: false,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }
}
