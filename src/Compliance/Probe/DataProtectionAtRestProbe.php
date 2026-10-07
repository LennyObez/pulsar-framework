<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether data at rest is sealed by cryptography that is present, keyed, and
 * observed sealing something.
 *
 * {@see ObservationId::SessionPayloadsSealed} was added to the required set so
 * that a BROKEN cipher is distinguishable from a working one here. Before it, a
 * deployment that bound a cipher which stored payloads in the clear, or opened a
 * modified one, reported exactly what a deployment with a sound cipher reported:
 * every requirement present, nothing exercised. Now the gap is named and carries
 * a remediation.
 *
 * THE SESSION FACT STILL CANNOT CARRY THESE CONTROLS, and that is deliberate
 * rather than a shortcoming to be fixed by widening it. The controls this probe
 * serves — GDPR Art 5(1)(f), CCPA 1798.150, NIST CSF PR.DS — regulate personal
 * and confidential data, and a sealed session is not an answer about a database
 * full of records. The estate join in
 * {@see \Pulsar\Compliance\Control\ProbeVerdict::reach()} holds the fact aside
 * and the finding says so in as many words: exercised, on another estate.
 *
 * SO THE ESTATES WERE MEASURED INSTEAD OF THE NAMING BEING WIDENED, which is what
 * the paragraph above used to promise and could not yet deliver.
 * {@see ObservationId::PersonalDataFieldSealed} is the personal-data half, and it
 * joins the required set here: {@see \Pulsar\Compliance\Evidence\PersonalDataSealObserver}
 * hands this deployment a field classified as personal data and reads what it
 * would store, so GDPR Art 5(1)(f) and CCPA 1798.150 — both declared over
 * {@see \Pulsar\Compliance\Control\ControlSubject::PersonalData} — now have a
 * fact on their own estate that can carry them, and both reach BOTH outcomes
 * depending on the deployment.
 *
 * NIST CSF PR.DS DOES NOT MOVE, and the arithmetic is worth stating rather than
 * leaving for a reader to discover: it is declared over
 * {@see \Pulsar\Compliance\Control\ControlSubject::ConfidentialInformation},
 * and a value classified as PERSONAL data is not an answer about the contracts,
 * pricing and source code that estate holds. It stays unsatisfiable on any
 * deployment this release can build, and the finding says which estates WERE
 * exercised so an operator is not sent to redo work that is done. Measuring that
 * estate is the next observer, not this one.
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
        return 'Whether the AEAD primitives are available, an encrypter was built, and a field '
            . 'this deployment classifies as personal data was observed being sealed, read back '
            . 'and defended against a modified copy.';
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
            ObservationId::SessionPayloadsSealed,
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
            'If a custom SessionPayloadCipherInterface is bound, make it an authenticated '
                . 'cipher that binds the sealed form to the session context, so a modified '
                . 'or transplanted payload is refused rather than opened.',
            'If a custom EncryptorInterface is bound, make it an authenticated cipher with a '
                . 'fresh nonce per call, so a field classified as personal data is concealed, '
                . 'refuses a modified copy, and does not seal two equal values alike.',
        ];
    }
}
