<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether direct identifiers are replaced by a service that is actually in
 * place, rather than by a policy that says they should be.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class PseudonymizationProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.pseudonymization';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Which pseudonymisation service resolved for this deployment.';
    }

    #[Override]
    #[NoDiscard]
    protected function scope(): ObservationId
    {
        return ObservationId::ScopeProcessesPersonalData;
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::PseudonymizationResolved,
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
            ObservationId::TokenVaultPersistence,
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
            'Bind PseudonymizationServiceInterface together with a pseudonym lookup '
                . 'that persists its mappings.',
        ];
    }
}
