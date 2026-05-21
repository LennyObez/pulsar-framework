<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Exception;

use NoDiscard;
use RuntimeException;

/**
 * Domain exception for booking operations.
 */
final class BookingException extends RuntimeException
{
    #[NoDiscard]
    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Cannot transition appointment from '{$from}' to '{$to}'.");
    }

    #[NoDiscard]
    public static function depositAlreadyPaid(string $bookingNumber): self
    {
        return new self("Deposit for booking {$bookingNumber} has already been paid.");
    }

    #[NoDiscard]
    public static function noDepositRequired(string $bookingNumber): self
    {
        return new self("No deposit is required for booking {$bookingNumber}.");
    }

    #[NoDiscard]
    public static function cannotReschedule(string $bookingNumber): self
    {
        return new self("Booking {$bookingNumber} cannot be rescheduled in its current state.");
    }

    #[NoDiscard]
    public static function appointmentNotFound(string $id): self
    {
        return new self("Appointment '{$id}' not found.");
    }

    #[NoDiscard]
    public static function serviceNotFound(string $id): self
    {
        return new self("Service '{$id}' not found.");
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    #[NoDiscard]
    public static function noAvailableSlots(string $date): self
    {
        return new self("No available time slots for date {$date}.");
    }

    #[NoDiscard]
    public static function tooSoon(int $minAdvanceHours): self
    {
        return new self("Appointments must be booked at least {$minAdvanceHours} hours in advance.");
    }

    #[NoDiscard]
    public static function tooFarAhead(int $maxAdvanceDays): self
    {
        return new self("Appointments cannot be booked more than {$maxAdvanceDays} days in advance.");
    }

    #[NoDiscard]
    public static function cancellationTooLate(int $cancellationPolicyHours): self
    {
        return new self("Appointments must be cancelled at least {$cancellationPolicyHours} hours before the scheduled time.");
    }

    #[NoDiscard]
    public static function slotNotAvailable(string $slotId): self
    {
        return new self("Time slot '{$slotId}' is not available.");
    }

    #[NoDiscard]
    public static function smsDeliveryFailed(string $provider, string $reason): self
    {
        return new self("SMS delivery via {$provider} failed: {$reason}");
    }

    #[NoDiscard]
    public static function calendarSyncFailed(string $reason): self
    {
        return new self("Google Calendar sync failed: {$reason}");
    }
}
