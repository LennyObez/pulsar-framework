<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Infrastructure\Clock;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\ClockInterface;

/**
 * Production clock using system time.
 */
#[Internal]
final readonly class SystemClock implements ClockInterface
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
