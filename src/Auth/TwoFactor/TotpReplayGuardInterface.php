<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Prevents TOTP code replay attacks.
 *
 * Implementations track which time steps have been used for each identity so
 * that the same code cannot be accepted more than once.
 *
 * The key scope is (identityId, timeStep): not (identityId, code) and not
 * (identityId, purpose, timeStep). ASVS 2.8.4 requires a one-time verifier to
 * be usable exactly once within its validity period; keying on the purpose
 * would make one code redeemable once per purpose, so a captured Login code
 * would still buy a StepUp.
 *
 * Implementations must retain an entry for the whole span over which the
 * verifier still accepts its time step -- (2 * verificationWindow + 1) * period
 * seconds -- plus a margin for clock skew. Forgetting earlier reopens the
 * replay the guard exists to close.
 * @api
 */
#[Api(since: '1.0.0')]
interface TotpReplayGuardInterface
{
    /**
     * Mark a time step as used and return whether it was previously unused.
     *
     * @param string $identityId Identity that submitted the code
     * @param int $timeStep The accepted TOTP time step (counter value, not timestamp)
     * @param int $timestamp Unix timestamp of the verification (for TTL pruning)
     *
     * @return bool true if the time step was not previously used (now marked), false if already used
     */
    public function markUsed(string $identityId, int $timeStep, int $timestamp): bool;
}
