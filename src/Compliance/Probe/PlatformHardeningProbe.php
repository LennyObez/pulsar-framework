<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the platform-level protections are present and none of them is inert.
 *
 * Headers and HSTS are config reads and corroborate only. The decisive fact is
 * that nothing declared is silently disabled — the failure mode that let three
 * anti-spam features go inert without a single log line.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class PlatformHardeningProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.platform_hardening';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether any platform protection is bound but inert, and how the header and throttling settings read.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::SecurityFeaturesIntact,
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
            ObservationId::SecurityHeadersConfigured,
            ObservationId::TransportSecurityEnforced,
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
            'Bind the optional services named in the evidence so no platform '
                . 'protection is inert.',
            'Enable the security-headers middleware and HSTS in config/security.php.',
        ];
    }
}
