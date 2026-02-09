<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Prevents TOTP code replay attacks.
 *
 * Implementations track which codes have been used for each identity
 * within the current time window to prevent the same code from being
 * accepted more than once.
 */
#[Api]
interface TotpReplayGuardInterface
{
    /**
     * Mark a TOTP code as used and return whether it was previously unused.
     *
     * @param string $identityId Identity that submitted the code
     * @param string $code The TOTP code
     * @param int $timestamp Unix timestamp of the verification
     *
     * @return bool true if the code was not previously used (now marked), false if already used
     */
    public function markUsed(string $identityId, string $code, int $timestamp): bool;
}
