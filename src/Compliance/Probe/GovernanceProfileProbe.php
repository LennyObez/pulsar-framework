<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the regulatory context the organisation declared was resolved into
 * something the running application obeys.
 *
 * Genuinely observable, unlike most governance requirements: the profile is a
 * DTO in the container, computed from the enabled frameworks, and the session,
 * password and retention settings were tightened from it at boot.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class GovernanceProfileProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.governance_profile';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether a compliance profile was resolved from the enabled frameworks and applied.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::ComplianceProfileResolved,
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
            ObservationId::SecurityFeaturesIntact,
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
            'List the frameworks the organisation must satisfy in '
                . 'config/compliance.php; with none enabled no profile is resolved and '
                . 'nothing is tightened.',
        ];
    }
}
