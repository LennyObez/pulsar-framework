<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Collects 2FA events for observability (e.g., Studio dashboard).
 *
 * Uses an allowlist approach: only known-safe metadata keys are passed
 * (action, outcome, identity_id, purpose, reason, code_index, provider).
 * Unknown keys are stripped to prevent accidental secret leakage.
 */
#[Api(since: '1.0.0')]
interface AuthEventCollectorInterface
{
    /**
     * Record a two-factor authentication event.
     *
     * @param string $action The event action (e.g., '2fa_code_verified')
     * @param string $outcome The outcome (e.g., 'success', 'failure', 'denied')
     * @param string $identityId The identity involved
     * @param array<string, scalar> $metadata Allowlisted metadata (no secrets)
     */
    public function recordTwoFactorEvent(
        string $action,
        string $outcome,
        string $identityId,
        array $metadata = [],
    ): void;
}
