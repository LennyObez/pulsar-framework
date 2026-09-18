<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether session state is protected where it rests and where it travels.
 *
 * THE LOAD-BEARING FACT IS THE SEAL, and it did not used to be. This probe
 * rested on `session_encryption_resolved` alone — a config read saying a cipher
 * class had been constructed — and a config read cannot carry a control, so
 * SWIFT CSP 2.6 could not be Satisfied by any deployment at all. It is carried
 * now by {@see ObservationId::SessionPayloadsSealed}, which seals a synthetic
 * payload through the live cipher, opens it, modifies one byte and offers it as
 * another session; see {@see \Pulsar\Compliance\Evidence\SessionSealObserver}.
 *
 * The binding fact stays REQUIRED beside it, and it is not redundant. It is what
 * says the cipher that was exercised is the cipher in the write path: the
 * composition root binds one instance and hands that instance to the session
 * manager. It proves nothing on its own — it is Declared, so it cannot carry the
 * control however it reads — and that is the residue this pairing leaves
 * standing: a grade-blind requirement slot filled by a fact that establishes
 * nothing, deferred deliberately to its own decision.
 *
 * The cookie flags corroborate and decide nothing: a Secure, HttpOnly cookie
 * carrying an unsealed payload protects the transport and not the data.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class SecureSessionProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.secure_session';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether a session payload put through the live cipher comes back sealed, recoverable and bound to its session.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::SessionPayloadsSealed,
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
            ObservationId::SessionCookiesHardened,
            ObservationId::SessionEncryptionConfigured,
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
            'Enable session.encryption in config/security.php so SessionEncryption is '
                . 'built and bound.',
            'If a custom SessionPayloadCipherInterface is bound, make it an authenticated '
                . 'cipher that binds the sealed form to the session context, so a modified '
                . 'or transplanted payload is refused rather than opened.',
            'Set the session cookie Secure and HttpOnly with a SameSite policy.',
        ];
    }
}
