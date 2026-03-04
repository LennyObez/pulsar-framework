<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;

#[CoversClass(AppointmentStatus::class)]
final class AppointmentStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{AppointmentStatus, AppointmentStatus, bool}>
     */
    public static function transitionProvider(): iterable
    {
        // Valid transitions from Requested
        yield 'requested -> confirmed' => [AppointmentStatus::Requested, AppointmentStatus::Confirmed, true];
        yield 'requested -> cancelled' => [AppointmentStatus::Requested, AppointmentStatus::Cancelled, true];
        yield 'requested -> completed (invalid)' => [AppointmentStatus::Requested, AppointmentStatus::Completed, false];

        // Valid transitions from Confirmed
        yield 'confirmed -> deposit_paid' => [AppointmentStatus::Confirmed, AppointmentStatus::DepositPaid, true];
        yield 'confirmed -> reminded' => [AppointmentStatus::Confirmed, AppointmentStatus::Reminded, true];
        yield 'confirmed -> in_progress' => [AppointmentStatus::Confirmed, AppointmentStatus::InProgress, true];
        yield 'confirmed -> cancelled' => [AppointmentStatus::Confirmed, AppointmentStatus::Cancelled, true];
        yield 'confirmed -> rescheduled' => [AppointmentStatus::Confirmed, AppointmentStatus::Rescheduled, true];

        // Valid transitions from DepositPaid
        yield 'deposit_paid -> reminded' => [AppointmentStatus::DepositPaid, AppointmentStatus::Reminded, true];
        yield 'deposit_paid -> in_progress' => [AppointmentStatus::DepositPaid, AppointmentStatus::InProgress, true];
        yield 'deposit_paid -> cancelled' => [AppointmentStatus::DepositPaid, AppointmentStatus::Cancelled, true];
        yield 'deposit_paid -> rescheduled' => [AppointmentStatus::DepositPaid, AppointmentStatus::Rescheduled, true];

        // Valid transitions from Reminded
        yield 'reminded -> in_progress' => [AppointmentStatus::Reminded, AppointmentStatus::InProgress, true];
        yield 'reminded -> cancelled' => [AppointmentStatus::Reminded, AppointmentStatus::Cancelled, true];
        yield 'reminded -> no_show' => [AppointmentStatus::Reminded, AppointmentStatus::NoShow, true];

        // Valid transitions from InProgress
        yield 'in_progress -> completed' => [AppointmentStatus::InProgress, AppointmentStatus::Completed, true];
        yield 'in_progress -> no_show' => [AppointmentStatus::InProgress, AppointmentStatus::NoShow, true];
        yield 'in_progress -> cancelled (invalid)' => [AppointmentStatus::InProgress, AppointmentStatus::Cancelled, false];

        // Terminal states
        yield 'completed -> any (invalid)' => [AppointmentStatus::Completed, AppointmentStatus::Cancelled, false];
        yield 'cancelled -> any (invalid)' => [AppointmentStatus::Cancelled, AppointmentStatus::Confirmed, false];
        yield 'no_show -> any (invalid)' => [AppointmentStatus::NoShow, AppointmentStatus::Completed, false];
        yield 'rescheduled -> any (invalid)' => [AppointmentStatus::Rescheduled, AppointmentStatus::Confirmed, false];
    }

    #[DataProvider('transitionProvider')]
    public function testCanTransitionTo(AppointmentStatus $from, AppointmentStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    /**
     * @return iterable<string, array{AppointmentStatus, bool}>
     */
    public static function terminalProvider(): iterable
    {
        yield 'completed is terminal' => [AppointmentStatus::Completed, true];
        yield 'cancelled is terminal' => [AppointmentStatus::Cancelled, true];
        yield 'no_show is terminal' => [AppointmentStatus::NoShow, true];
        yield 'rescheduled is terminal' => [AppointmentStatus::Rescheduled, true];
        yield 'requested is not terminal' => [AppointmentStatus::Requested, false];
        yield 'confirmed is not terminal' => [AppointmentStatus::Confirmed, false];
        yield 'in_progress is not terminal' => [AppointmentStatus::InProgress, false];
    }

    #[DataProvider('terminalProvider')]
    public function testIsTerminal(AppointmentStatus $status, bool $expected): void
    {
        self::assertSame($expected, $status->isTerminal());
    }

    /**
     * @return iterable<string, array{AppointmentStatus, bool}>
     */
    public static function activeProvider(): iterable
    {
        yield 'requested is active' => [AppointmentStatus::Requested, true];
        yield 'confirmed is active' => [AppointmentStatus::Confirmed, true];
        yield 'deposit_paid is active' => [AppointmentStatus::DepositPaid, true];
        yield 'reminded is active' => [AppointmentStatus::Reminded, true];
        yield 'in_progress is active' => [AppointmentStatus::InProgress, true];
        yield 'completed is not active' => [AppointmentStatus::Completed, false];
        yield 'cancelled is not active' => [AppointmentStatus::Cancelled, false];
    }

    #[DataProvider('activeProvider')]
    public function testIsActive(AppointmentStatus $status, bool $expected): void
    {
        self::assertSame($expected, $status->isActive());
    }

    public function testAllStatusesHaveStringValues(): void
    {
        foreach (AppointmentStatus::cases() as $status) {
            self::assertNotEmpty($status->value);
        }
    }
}
