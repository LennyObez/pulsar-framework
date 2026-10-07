<?php

declare(strict_types=1);

namespace Pulsar\Testing\Clock;

use Psr\Clock\ClockInterface as PsrClock;
use Pulsar\Api\Api;

/**
 * Injectable clock contract for deterministic time control.
 *
 * All framework time references should use this interface instead of
 * direct time()/date() calls, enabling tests to freeze or advance time.
 *
 * Extends the PHP-FIG standard {@see PsrClock} (PSR-20) rather than restating it:
 * `now(): DateTimeImmutable` is exactly PSR-20's contract, so declaring our own
 * copy gained nothing and cost interoperability — no PSR-20-aware library could
 * consume a Pulsar clock, and no third-party PSR-20 clock could be injected here.
 * The extra {@see self::timestamp()} is the only thing this interface adds on top
 * of the standard, kept because Unix timestamps are pervasive in the framework's
 * hot paths (token windows, replay guards, cache TTLs) and re-deriving one from a
 * DateTimeImmutable at every call site is wasteful.
 *
 * Implementations therefore satisfy PSR-20 automatically: any existing
 * implementation already declares `now(): DateTimeImmutable`.
 * @api
 */
#[Api(since: '1.0.0')]
interface ClockInterface extends PsrClock
{
    /**
     * Get the current Unix timestamp.
     */
    public function timestamp(): int;
}
