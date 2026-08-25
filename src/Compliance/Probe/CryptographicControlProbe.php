<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the cryptography the framework depends on is available and keyed.
 *
 * Two facts, and both are needed: algorithms that exist but no master key means
 * nothing is encrypted, and a key with no libsodium means nothing can be.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class CryptographicControlProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.cryptographic_control';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether libsodium and the approved algorithms are available, and a master key was derived.';
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
            ObservationId::MasterKeyResolved,
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
            ObservationId::FipsValidatedCryptography,
            ObservationId::MasterKeyMaterial,
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
            'Install ext-sodium; without it the framework has no AEAD primitive at '
                . 'all.',
            'Set PULSAR_MASTER_KEY to a 32-byte hex value so MasterKey can derive the '
                . 'domain-separated subkeys every encrypting subsystem asks for.',
            'Deploy against an OpenSSL build providing AES-256-GCM and HMAC-SHA-256.',
        ];
    }
}
