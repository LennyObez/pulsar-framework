<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the key hierarchy this deployment encrypts under actually derives.
 *
 * ISO 27001 A.8.24 says "including cryptographic key management", and the
 * control was probed by {@see CryptographicControlProbe} — libsodium is loaded,
 * and a `MasterKey` object exists. Neither of those is key management. A key
 * object that exists proves a constructor ran; it says nothing about whether the
 * KDF beneath it works, reproduces, or separates domains.
 *
 * This probe's deciding fact is {@see ObservationId::KeyDerivationVerified},
 * which is `runtime.master_key_derived` running `sodium_crypto_kdf_derive_from_key`
 * against the key in service and asserting three properties of what came back:
 *
 *  - the subkey is the length that was requested;
 *  - the same (subkey id, context) reproduces it — without which nothing sealed
 *    under a derived key could ever be reopened;
 *  - a different context yields different material, which is the domain
 *    separation ADR-0006 has every encrypting subsystem relying on.
 *
 * WHAT IT DOES NOT COVER, and the reason A.8.24's requirement text in
 * {@see \Pulsar\Compliance\Frameworks\Iso27001Mapping} says so in the report
 * rather than only here: A.8.24 asks for rules to be "defined AND implemented".
 * Software can be asked about the implemented half. The defined half — the
 * documented cryptographic policy, the key lifecycle from generation through
 * rotation to destruction, the custody arrangements — is a document, and a
 * probe that graded a document from the presence of a key would be ADR-0041's
 * defect wearing a different noun. Rotation is likewise left out of the required
 * facts on purpose: {@see \Pulsar\Security\Crypto\MasterKey} supports a previous
 * key so that a rotation can be completed, but a deployment that is not
 * mid-rotation has no previous key and is not thereby non-compliant.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class KeyManagementProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.key_management';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the KDF derives subkeys from the key in service, reproducibly and '
            . 'with domain separation, and which class provides that key.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::KeyDerivationVerified,
            ObservationId::MasterKeyResolved,
            ObservationId::CryptographicCapability,
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
            ObservationId::MasterKeyMaterial,
            ObservationId::FipsValidatedCryptography,
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
            'Set PULSAR_MASTER_KEY to 64 hex characters decoding to 32 bytes, so a key '
                . 'hierarchy exists to derive from.',
            'Install a libsodium build providing a conforming '
                . 'sodium_crypto_kdf_derive_from_key(); without it no subsystem can seal '
                . 'anything under a domain-separated subkey.',
            'Check the boot log: a supplied key that fails to parse leaves the crypto stack '
                . 'unbound and is logged as an error by SecurityWiring.',
        ];
    }
}
