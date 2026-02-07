<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Clock;

use DateTimeImmutable;
use Override;
use Pulsar\Extension\Payments\Contract\ClockInterface;

/**
 * Production clock using system time.
 */
final readonly class SystemClock implements ClockInterface
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
