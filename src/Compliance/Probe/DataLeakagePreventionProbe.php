<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether identifiers are actually replaced before data leaves a trust boundary.
 *
 * Pseudonymisation is the decisive fact; tokenisation and response headers
 * corroborate. Output escaping and CSP are worth reporting but cannot carry
 * this control on their own: they are settings, and a setting has never stopped
 * a leak by itself.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class DataLeakagePreventionProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.data_leakage_prevention';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Which pseudonymisation service resolved, and whether a token vault backs it.';
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
            ObservationId::SecurityHeadersConfigured,
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
            'Bind PseudonymizationServiceInterface so direct identifiers can be '
                . 'replaced before data crosses a boundary.',
            'Configure a database connection so the token vault persists rather than '
                . 'holding mappings in process memory.',
        ];
    }
}
