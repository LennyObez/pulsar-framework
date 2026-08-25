<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether something in the deployment decides how long data is kept.
 *
 * The resolved policy is the fact; the profile's day counts corroborate. A
 * retention period configured with no policy object to apply it is a number in
 * a file.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class DataRetentionProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.data_retention';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Which retention policy resolved, and what retention the profile requires.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::RetentionScheduleResolved,
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
            ObservationId::RetentionBounded,
            ObservationId::ErasureSubsystemResolved,
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
            'Bind RetentionPolicyInterface so retention periods are applied rather '
                . 'than merely configured.',
        ];
    }
}
