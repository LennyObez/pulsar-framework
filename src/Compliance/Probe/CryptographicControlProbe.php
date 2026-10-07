<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the cryptography this deployment holds is available, keyed, and
 * observed protecting personal data.
 *
 * THE FIRST TWO FACTS ARE PREREQUISITES AND NEITHER IS EVIDENCE. Algorithms that
 * exist but no master key means nothing is encrypted, and a key with no libsodium
 * means nothing can be — so both stay required. Neither can decide the control:
 * `cryptographic_capability` is graded
 * {@see \Pulsar\Compliance\Control\ObservationGrade::Available} because
 * `extension_loaded('sodium')` answers the same on a deployment that encrypts
 * everything and on one that encrypts nothing, and `master_key_resolved` names a
 * class. On those two alone GDPR Art 32 was Satisfied in the shipped default, and
 * ADR-0061 took that away without giving an operator anything they did not
 * already know.
 *
 * SO {@see ObservationId::PersonalDataFieldSealed} JOINS THEM, and it is the fact
 * that can carry the control. GDPR Art 32(1)(a) names "the pseudonymisation and
 * encryption of personal data", and this is the encryption half, measured:
 * {@see \Pulsar\Compliance\Evidence\PersonalDataSealObserver} hands the
 * deployment a field classified as personal data and reads back what it would
 * store — concealed, recoverable, refusing a modified copy, and not repeating
 * itself across equal values. The pseudonymisation half is measured too, by
 * {@see PseudonymizationProbe}, and is deliberately NOT required here: Art 32
 * says "as appropriate", so a deployment that encrypts its personal data and
 * pseudonymises nothing has not failed Article 32, and requiring both would
 * invent an obligation the article does not impose.
 *
 * NIS2 ART 21(h) CITES THIS PROBE TOO AND DOES NOT MOVE, which is stated rather
 * than left as arithmetic for a reader. It is declared over
 * {@see \Pulsar\Compliance\Control\ControlSubject::CryptographicPlatform} —
 * what the build offers, judged against a policy — and the only fact about that
 * estate is the loaded extension, at a grade that proves nothing. So the new
 * requirement gives it a fact it cannot use and a gap line it can: a deployment
 * with no encryptor now reports that nothing seals a classified value, which is
 * squarely what "policies and procedures regarding the use of cryptography" is
 * about, while a deployment with one still reports the control as claimed and not
 * observed.
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
        return 'Whether libsodium and the approved algorithms are available, a master key was '
            . 'derived, and a field this deployment classifies as personal data was observed '
            . 'being sealed with them.';
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
            ObservationId::PersonalDataFieldSealed,
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
            'If a custom EncryptorInterface is bound, make it an authenticated cipher with a '
                . 'fresh nonce per call: a field classified as personal data has to come back '
                . 'byte for byte, a modified copy has to be refused, and two equal values must '
                . 'not seal alike.',
        ];
    }
}
