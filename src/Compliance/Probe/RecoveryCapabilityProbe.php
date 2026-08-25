<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether this deployment can restore itself.
 *
 * There is no backup or restore primitive in the framework at all. This probe
 * asks for one by name and reports the gap when nothing answers, which is the
 * whole reason it exists: NIST CSF RC.RP used to be a Partial literal written
 * beside a description of health checks and worker restarts, neither of which
 * restores anything.
 *
 * The control cannot honestly be scoped out — no deployment can assert that
 * recovery does not apply to it — so it stays red until someone builds the
 * primitive, installs a package that provides it, or removes the framework from
 * enabled_frameworks. Forcing that choice is the design's job; resolving it is not.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class RecoveryCapabilityProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.recovery_capability';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether any backup and restore primitive is in service in this deployment.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::BackupPrimitiveResolved,
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
            'Provide an implementation of a backup and restore contract and bind it; '
                . 'the framework ships none, so recovery is currently evidenced by nothing.',
            'If recovery is discharged entirely by infrastructure outside the '
                . 'application, remove this framework from enabled_frameworks rather than '
                . 'leaving the control claimed and unobserved.',
        ];
    }
}
