<?php

declare(strict_types=1);

namespace Pulsar\Testing\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Deterministic clock for testing: freeze, advance, or rewind time at will.
 *
 * Usage:
 *   $clock = TestClock::frozen();                    // freeze at current time
 *   $clock = TestClock::at('2024-01-15 10:00:00');   // freeze at specific time
 *   $clock->advance(seconds: 30);                    // move forward
 *   $clock->rewind(minutes: 5);                      // move backward
 */
#[Api(since: '1.0.0')]
final class TestClock implements ClockInterface
{
    private DateTimeImmutable $current;

    public function __construct(DateTimeImmutable $current)
    {
        $this->current = $current;
    }

    /**
     * Create a clock frozen at the current real time.
     */
    public static function frozen(): self
    {
        return new self(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * Create a clock frozen at a specific datetime string.
     */
    public static function at(string $datetime): self
    {
        return new self(new DateTimeImmutable($datetime, new DateTimeZone('UTC')));
    }

    /**
     * Create a clock frozen at a specific Unix timestamp.
     */
    public static function fromTimestamp(int $timestamp): self
    {
        $dt = DateTimeImmutable::createFromFormat('U', (string) $timestamp);

        if ($dt === false) {
            $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        return new self($dt->setTimezone(new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function timestamp(): int
    {
        return $this->current->getTimestamp();
    }

    /**
     * Advance the clock forward by the given duration.
     */
    public function advance(
        int $seconds = 0,
        int $minutes = 0,
        int $hours = 0,
        int $days = 0,
    ): void {
        $totalSeconds = $seconds + ($minutes * 60) + ($hours * 3600) + ($days * 86400);
        $this->current = $this->current->modify(sprintf('+%d seconds', $totalSeconds));
    }

    /**
     * Rewind the clock backward by the given duration.
     */
    public function rewind(
        int $seconds = 0,
        int $minutes = 0,
        int $hours = 0,
        int $days = 0,
    ): void {
        $totalSeconds = $seconds + ($minutes * 60) + ($hours * 3600) + ($days * 86400);
        $this->current = $this->current->modify(sprintf('-%d seconds', $totalSeconds));
    }

    /**
     * Set the clock to a specific point in time.
     */
    public function setTo(DateTimeImmutable $datetime): void
    {
        $this->current = $datetime;
    }
}
