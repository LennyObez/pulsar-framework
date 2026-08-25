<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether a second authentication factor is actually available to enforce.
 *
 * The rate limiter is supporting rather than required on purpose: an
 * unthrottled second factor is weaker, but it is still a second factor, and
 * conflating the two would report the same gap twice.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class MultiFactorAuthenticationProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.multi_factor_authentication';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Which two-factor manager resolved, and whether the profile requires a second factor.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::MfaSubsystemResolved,
        ];
    }

    /**
     * @return list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function supporting(): array
    {
        return [
            ObservationId::MfaPolicyRequired,
            ObservationId::AuthRateLimiterEnforcing,
        ];
    }

    /**
     * @return non-empty-list<non-empty-string>
     */
    #[Override]
    #[NoDiscard]
    protected function remediations(): array
    {
        return [
            'Bind TwoFactorManagerInterface to TwoFactorManager with a TOTP secret '
                . 'store and a recovery-code store.',
            'Bind a TwoFactorRateLimiterInterface that actually throttles; '
                . 'AllowAllTwoFactorRateLimiter permits unlimited guessing.',
        ];
    }
}
