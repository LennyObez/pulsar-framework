<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use function hash_equals;

/**
 * Verifies TOTP codes with a configurable time window to account for clock drift.
 */
final readonly class TotpVerifier
{
    public function __construct(
        private TotpGenerator $generator,
        private int $window = 1,
    ) {}

    /**
     * Verify a TOTP code against the given secret.
     *
     * Checks the code against the current time step and ±window adjacent steps.
     *
     * @param string $secret Raw binary secret
     * @param string $code User-provided code
     * @param int|null $timestamp Unix timestamp (defaults to current time)
     */
    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $timestamp ??= time();

        for ($i = -$this->window; $i <= $this->window; $i++) {
            $checkTime = $timestamp + ($i * 30);
            $expected = $this->generator->computeCode($secret, $checkTime);

            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }
}
