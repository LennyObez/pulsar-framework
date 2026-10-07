<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the request-handling hardening controls are wired and none of them
 * went silently inert.
 *
 * CSRF, security headers and debug mode are all configuration reads, so they
 * corroborate but cannot decide. What decides is the wiring-contract inspector,
 * which is the only mechanism in the tree that distinguishes bound from working.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ApplicationHardeningProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.application_hardening';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether any request-hardening feature is bound but inert, and how the hardening flags read.';
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
            ObservationId::CsrfProtectionActive,
            ObservationId::SecurityHeadersConfigured,
            ObservationId::DebugModeDisabled,
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
            'Bind the optional services named in the evidence so no hardening feature '
                . 'stays bound-but-inert.',
            'Enable CSRF protection and the security-headers middleware in '
                . 'config/security.php.',
            'Disable debug mode outside development: stack traces and internals leak '
                . 'to clients.',
        ];
    }
}
