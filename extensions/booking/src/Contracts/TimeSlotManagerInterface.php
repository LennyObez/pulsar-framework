<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Booking\Domain\TimeSlot;

/**
 * Manages time slot availability.
 */
#[Api(since: '1.0.0')]
interface TimeSlotManagerInterface
{
    /**
     * Get available time slots for a given date and service duration.
     *
     * @return list<TimeSlot>
     */
    public function getAvailable(DateTimeImmutable $date, int $durationMinutes): array;

    /**
     * Block a time slot for an appointment.
     */
    public function block(string $slotId, string $appointmentId): void;

    /**
     * Release a blocked time slot, making it available again.
     */
    public function release(string $slotId): void;
}
