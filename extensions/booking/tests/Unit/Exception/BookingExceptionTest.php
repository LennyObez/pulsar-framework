<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Exception\BookingException;

#[CoversClass(BookingException::class)]
final class BookingExceptionTest extends TestCase
{
    #[Test]
    public function invalidTransitionIncludesFromAndTo(): void
    {
        $e = BookingException::invalidTransition('requested', 'completed');

        self::assertStringContainsString('requested', $e->getMessage());
        self::assertStringContainsString('completed', $e->getMessage());
    }

    #[Test]
    public function depositAlreadyPaidIncludesBookingNumber(): void
    {
        $e = BookingException::depositAlreadyPaid('BK-2026-001');

        self::assertStringContainsString('BK-2026-001', $e->getMessage());
    }

    #[Test]
    public function noDepositRequiredIncludesBookingNumber(): void
    {
        $e = BookingException::noDepositRequired('BK-2026-002');

        self::assertStringContainsString('BK-2026-002', $e->getMessage());
    }

    #[Test]
    public function cannotRescheduleIncludesBookingNumber(): void
    {
        $e = BookingException::cannotReschedule('BK-2026-003');

        self::assertStringContainsString('BK-2026-003', $e->getMessage());
    }

    #[Test]
    public function appointmentNotFoundIncludesId(): void
    {
        $e = BookingException::appointmentNotFound('appt-99');

        self::assertStringContainsString('appt-99', $e->getMessage());
    }

    #[Test]
    public function serviceNotFoundIncludesId(): void
    {
        $e = BookingException::serviceNotFound('svc-42');

        self::assertStringContainsString('svc-42', $e->getMessage());
    }

    #[Test]
    public function noAvailableSlotsIncludesDate(): void
    {
        $e = BookingException::noAvailableSlots('2026-04-01');

        self::assertStringContainsString('2026-04-01', $e->getMessage());
    }

    #[Test]
    public function tooSoonIncludesHours(): void
    {
        $e = BookingException::tooSoon(24);

        self::assertStringContainsString('24', $e->getMessage());
    }

    #[Test]
    public function tooFarAheadIncludesDays(): void
    {
        $e = BookingException::tooFarAhead(90);

        self::assertStringContainsString('90', $e->getMessage());
    }

    #[Test]
    public function cancellationTooLateIncludesHours(): void
    {
        $e = BookingException::cancellationTooLate(4);

        self::assertStringContainsString('4', $e->getMessage());
    }

    #[Test]
    public function slotNotAvailableIncludesSlotId(): void
    {
        $e = BookingException::slotNotAvailable('slot-x');

        self::assertStringContainsString('slot-x', $e->getMessage());
    }

    #[Test]
    public function smsDeliveryFailedIncludesProviderAndReason(): void
    {
        $e = BookingException::smsDeliveryFailed('twilio', 'rate limit exceeded');

        self::assertStringContainsString('twilio', $e->getMessage());
        self::assertStringContainsString('rate limit exceeded', $e->getMessage());
    }

    #[Test]
    public function calendarSyncFailedIncludesReason(): void
    {
        $e = BookingException::calendarSyncFailed('token expired');

        self::assertStringContainsString('token expired', $e->getMessage());
    }

    /**
     * @return iterable<string, array{BookingException}>
     */
    public static function allFactoryMethodsProvider(): iterable
    {
        yield 'invalidTransition' => [BookingException::invalidTransition('a', 'b')];
        yield 'depositAlreadyPaid' => [BookingException::depositAlreadyPaid('BK-1')];
        yield 'noDepositRequired' => [BookingException::noDepositRequired('BK-2')];
        yield 'cannotReschedule' => [BookingException::cannotReschedule('BK-3')];
        yield 'appointmentNotFound' => [BookingException::appointmentNotFound('id')];
        yield 'serviceNotFound' => [BookingException::serviceNotFound('id')];
        yield 'noAvailableSlots' => [BookingException::noAvailableSlots('date')];
        yield 'tooSoon' => [BookingException::tooSoon(1)];
        yield 'tooFarAhead' => [BookingException::tooFarAhead(1)];
        yield 'cancellationTooLate' => [BookingException::cancellationTooLate(1)];
        yield 'slotNotAvailable' => [BookingException::slotNotAvailable('s')];
        yield 'smsDeliveryFailed' => [BookingException::smsDeliveryFailed('p', 'r')];
        yield 'calendarSyncFailed' => [BookingException::calendarSyncFailed('r')];
    }

    #[Test]
    #[DataProvider('allFactoryMethodsProvider')]
    public function allFactoryMethodsReturnBookingException(BookingException $exception): void
    {
        self::assertInstanceOf(BookingException::class, $exception);
        self::assertNotEmpty($exception->getMessage());
    }
}
