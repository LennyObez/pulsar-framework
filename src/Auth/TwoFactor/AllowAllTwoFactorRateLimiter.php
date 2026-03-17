<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Override;
use Pulsar\Api\Internal;

/**
 * Explicit "no rate limit" placeholder for dev/test contexts.
 *
 * SEC-2FA-01: TwoFactorManager and TotpVerifier are fail-closed when no rate
 * limiter is wired, which is correct for production but breaks development
 * setups that need to exercise the verification path without configuring a
 * real bucket. Wiring this class explicitly makes the intent visible: "I
 * acknowledge there is no rate limit here." Production deployments are
 * refused via {@see \Pulsar\Deploy\Check\TwoFactorRateLimiterReadinessCheck}.
 *
 * Never use in production. Use a real implementation (token bucket / leaky
 * bucket / Redis-backed) scoped to identity + IP + timeframe.
 */
#[Internal(reason: 'Dev/test placeholder; refused in production deploy check')]
final readonly class AllowAllTwoFactorRateLimiter implements TwoFactorRateLimiterInterface
{
    #[Override]
    public function attempt(string $identityId, TwoFactorPurpose $purpose, array $context = []): bool
    {
        return true;
    }

    #[Override]
    public function reset(string $identityId): void
    {
        // no-op
    }
}
