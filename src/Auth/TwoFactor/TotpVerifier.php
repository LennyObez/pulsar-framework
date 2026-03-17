<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;
use SensitiveParameter;

use function hash_equals;
use function intdiv;

/**
 * Verifies TOTP codes with a configurable time window to account for clock drift.
 *
 * Returns the accepted time step on success for replay guard keying and diagnostics.
 */
#[Api(since: '1.0.0')]
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
     * When a replay guard, identity ID, and purpose are provided, ensures the same
     * time step cannot be accepted twice within the window.
     *
     * Returns the accepted time step on match (for replay guard keying), or null on failure.
     *
     * @param string $secret Raw binary secret
     * @param string $code User-provided code
     * @param int|null $timestamp Unix timestamp (defaults to current time)
     * @param TotpReplayGuardInterface|null $replayGuard Replay protection (recommended for production)
     * @param string|null $identityId Identity submitting the code (required with replay guard)
     * @param TwoFactorPurpose $purpose The verification purpose
     */
    public function verify(
        #[SensitiveParameter]
        string $secret,
        #[SensitiveParameter]
        string $code,
        ?int $timestamp = null,
        ?TotpReplayGuardInterface $replayGuard = null,
        ?string $identityId = null,
        TwoFactorPurpose $purpose = TwoFactorPurpose::Login,
    ): ?int {
        $timestamp ??= time();
        $period = $this->generator->period();

        for ($i = -$this->window; $i <= $this->window; $i++) {
            $checkTime = $timestamp + ($i * $period);
            $expected = $this->generator->computeCode($secret, $checkTime);

            if (hash_equals($expected, $code)) {
                $timeStep = intdiv($checkTime, $period);

                // SEC-2FA-01: fail-closed on replay protection. When the caller
                // identifies the identity, a replay guard MUST be provided —
                // otherwise the same TOTP code can be replayed within the time
                // window. Pass `null` for `identityId` to bypass replay checks
                // for non-identity-bound flows (one-shot bootstrap secrets),
                // which is explicit and visible in code instead of masked by a
                // null guard.
                if ($identityId !== null) {
                    if ($replayGuard === null) {
                        return null;
                    }

                    if (!$replayGuard->markUsed($identityId, $purpose, $timeStep, $timestamp)) {
                        return null;
                    }
                }

                return $timeStep;
            }
        }

        return null;
    }
}
