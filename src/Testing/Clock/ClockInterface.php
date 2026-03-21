<?php

declare(strict_types=1);

namespace Pulsar\Testing\Clock;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Injectable clock contract for deterministic time control.
 *
 * All framework time references should use this interface instead of
 * direct time()/date() calls, enabling tests to freeze or advance time.
 * @api
 */
#[Api(since: '1.0.0')]
interface ClockInterface
{
    /**
     * Get the current time as a DateTimeImmutable.
     */
    public function now(): DateTimeImmutable;

    /**
     * Get the current Unix timestamp.
     */
    public function timestamp(): int;
}
