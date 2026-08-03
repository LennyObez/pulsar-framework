<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;

#[CoversClass(Appointment::class)]
final class AppointmentTest extends TestCase
{
    private function makeAppointment(
        AppointmentStatus $status = AppointmentStatus::Requested,
        ?Money $depositAmount = null,
        bool $depositPaid = false,
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
            scheduledAt: new DateTimeImmutable('2026-04-15 10:00:00'),
            duration: 60,
            depositAmount: $depositAmount,
            depositPaid: $depositPaid,
            notes: 'Test notes',
            reminderSent: false,
            createdAt: new DateTimeImmutable('2026-03-01 09:00:00'),
            updatedAt: new DateTimeImmutable('2026-03-01 09:00:00'),
        );
    }

    public function testTransitionToValidStatus(): void
    {
        $appointment = $this->makeAppointment();

        $confirmed = $appointment->transitionTo(AppointmentStatus::Confirmed);

        self::assertSame(AppointmentStatus::Confirmed, $confirmed->status);
        self::assertSame(AppointmentStatus::Requested, $appointment->status);
    }

    public function testTransitionToInvalidStatusThrows(): void
    {
        $appointment = $this->makeAppointment(AppointmentStatus::Completed);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('Cannot transition');

        $appointment->transitionTo(AppointmentStatus::Cancelled);
    }

    public function testMarkDepositPaid(): void
    {
        $deposit = Money::of(2000, Currency::USD);
        $appointment = $this->makeAppointment(
            status: AppointmentStatus::Confirmed,
            depositAmount: $deposit,
        );

        $paid = $appointment->markDepositPaid();

        self::assertTrue($paid->depositPaid);
        self::assertSame(AppointmentStatus::DepositPaid, $paid->status);
    }

    public function testMarkDepositPaidThrowsWhenAlreadyPaid(): void
    {
        $deposit = Money::of(2000, Currency::USD);
        $appointment = $this->makeAppointment(
            depositAmount: $deposit,
            depositPaid: true,
        );

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('already been paid');

        $appointment->markDepositPaid();
    }

    public function testMarkDepositPaidThrowsWhenNoDeposit(): void
    {
        $appointment = $this->makeAppointment();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('No deposit');

        $appointment->markDepositPaid();
    }

    public function testMarkReminderSent(): void
    {
        $appointment = $this->makeAppointment();

        self::assertFalse($appointment->reminderSent);

        $reminded = $appointment->markReminderSent();

        self::assertTrue($reminded->reminderSent);
        self::assertFalse($appointment->reminderSent);
    }

    public function testReschedule(): void
    {
        $appointment = $this->makeAppointment(AppointmentStatus::Confirmed);
        $newDate = new DateTimeImmutable('2026-05-01 14:00:00');

        $rescheduled = $appointment->reschedule($newDate, 90);

        self::assertSame(AppointmentStatus::Rescheduled, $rescheduled->status);
        self::assertSame($newDate, $rescheduled->scheduledAt);
        self::assertSame(90, $rescheduled->duration);
        self::assertFalse($rescheduled->reminderSent);
    }

    public function testRescheduleFromTerminalStatusThrows(): void
    {
        $appointment = $this->makeAppointment(AppointmentStatus::Completed);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('cannot be rescheduled');

        $appointment->reschedule(new DateTimeImmutable('2026-05-01'), 60);
    }

    public function testEndTime(): void
    {
        $appointment = $this->makeAppointment();

        $endTime = $appointment->endTime();

        self::assertSame('2026-04-15 11:00:00', $endTime->format('Y-m-d H:i:s'));
    }

    public function testNeedsDeposit(): void
    {
        $withDeposit = $this->makeAppointment(
            depositAmount: Money::of(2000, Currency::USD),
        );
        self::assertTrue($withDeposit->needsDeposit());

        $paidDeposit = $this->makeAppointment(
            depositAmount: Money::of(2000, Currency::USD),
            depositPaid: true,
        );
        self::assertFalse($paidDeposit->needsDeposit());

        $noDeposit = $this->makeAppointment();
        self::assertFalse($noDeposit->needsDeposit());

        $zeroDeposit = $this->makeAppointment(
            depositAmount: Money::zero(Currency::USD),
        );
        self::assertFalse($zeroDeposit->needsDeposit());
    }

    public function testImmutability(): void
    {
        $appointment = $this->makeAppointment();
        $confirmed = $appointment->transitionTo(AppointmentStatus::Confirmed);

        self::assertNotSame($appointment, $confirmed);
        self::assertSame(AppointmentStatus::Requested, $appointment->status);
        self::assertSame(AppointmentStatus::Confirmed, $confirmed->status);
    }
}
