<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether data at rest is sealed by cryptography that is present and keyed, and
 * by an encrypter that was actually built.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class DataProtectionAtRestProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.data_protection_at_rest';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the AEAD primitives are available and a session encrypter was built.';
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
            'Install ext-sodium and set PULSAR_MASTER_KEY so the encrypting '
                . 'subsystems can be built.',
            'Enable session.encryption in config/security.php.',
        ];
    }
}
