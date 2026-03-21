<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Time slot representing an available booking window.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TimeSlot
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $date,
        public DateTimeImmutable $startTime,
        public DateTimeImmutable $endTime,
        public bool $available,
        public ?string $appointmentId,
    ) {}

    /**
     * Block this time slot for an appointment.
     */
    #[NoDiscard]
    public function block(string $appointmentId): self
    {
        return clone($this, [
            'available' => false,
            'appointmentId' => $appointmentId,
        ]);
    }

    /**
     * Release this time slot, making it available again.
     */
    #[NoDiscard]
    public function release(): self
    {
        return clone($this, [
            'available' => true,
            'appointmentId' => null,
        ]);
    }

    /**
     * Get the duration of this slot in minutes.
     */
    #[NoDiscard]
    public function durationMinutes(): int
    {
        return (int) (($this->endTime->getTimestamp() - $this->startTime->getTimestamp()) / 60);
    }

    /**
     * Check if this slot overlaps with the given time range.
     */
    public function overlaps(DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        return $this->startTime < $end && $this->endTime > $start;
    }
}
