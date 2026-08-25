<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether protected health information is sealed both where it rests and where
 * it travels.
 *
 * Scope-gated on the operator's assertion about health data, because no code can
 * know whether a deployment handles PHI. The assertion is reproduced in the
 * report with its config key, so a deployment that scoped this out did so on the
 * record and an assessor can falsify it in one question.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class HealthDataProtectionProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.health_data_protection';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the cryptography is available, an encrypter was built, and the database session is encrypted.';
    }

    #[Override]
    #[NoDiscard]
    protected function scope(): ObservationId
    {
        return ObservationId::ScopeProcessesHealthData;
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::CryptographicCapability,
            ObservationId::SessionEncryptionResolved,
            ObservationId::DatabaseTransportEncrypted,
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
            ObservationId::MasterKeyResolved,
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
            'Install ext-sodium and set PULSAR_MASTER_KEY so the encrypting subsystems can be built.',
            'Enable session.encryption in config/security.php.',
            'Configure TLS on every database connection that crosses a network, so the health '
                . 'records do not travel in the clear.',
            'If this deployment handles no health data, set scope.processes_health_data = false '
                . 'in config/compliance.php.',
        ];
    }
}
