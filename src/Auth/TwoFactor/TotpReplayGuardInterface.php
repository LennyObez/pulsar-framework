<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Prevents TOTP code replay attacks.
 *
 * Implementations track which time steps have been used for each identity
 * and purpose to prevent the same code from being accepted more than once.
 *
 * The key scope is (identityId, purpose, timeStep): not (identityId, code).
 * This prevents replay across different purposes and correctly ties the
 * guard to the accepted time step rather than the code string.
 */
#[Api(since: '1.0.0')]
interface TotpReplayGuardInterface
{
    /**
     * Mark a time step as used and return whether it was previously unused.
     *
     * @param string $identityId Identity that submitted the code
     * @param TwoFactorPurpose $purpose The verification purpose
     * @param int $timeStep The accepted TOTP time step (counter value, not timestamp)
     * @param int $timestamp Unix timestamp of the verification (for TTL pruning)
     *
     * @return bool true if the time step was not previously used (now marked), false if already used
     */
    public function markUsed(string $identityId, TwoFactorPurpose $purpose, int $timeStep, int $timestamp): bool;
}
