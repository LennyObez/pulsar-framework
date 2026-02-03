<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contract;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Testable time abstraction.
 */
#[Api]
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
