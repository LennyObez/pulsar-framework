<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the deployment's configuration is under a regime that is actually
 * running: health checks that execute and a resolved compliance profile that
 * tightened the settings it governs.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ConfigurationManagementProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.configuration_management';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether health checks execute and a compliance profile was resolved over the enabled frameworks.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::HealthChecksExecuted,
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
            'Register health checks so the deployment has something to monitor; a '
                . 'runner with no checks observes nothing.',
            'List the frameworks the deployment must satisfy in config/compliance.php '
                . 'so a profile is resolved and applied.',
        ];
    }
}
