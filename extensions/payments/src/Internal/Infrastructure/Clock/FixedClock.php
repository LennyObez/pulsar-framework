<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Infrastructure\Clock;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\ClockInterface;

/**
 * Test clock with injectable, deterministic time.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class FixedClock implements ClockInterface
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {}

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Advance the clock by the given number of seconds.
     */
    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify('+' . $seconds . ' seconds');
    }

    /**
     * Set the clock to a specific time.
     */
    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
