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
 * SessionEncryption resolving is the load-bearing fact; the cookie flags
 * corroborate it. A Secure, HttpOnly cookie carrying an unencrypted payload
 * protects the transport and not the data.
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
        return 'Whether session payloads are encrypted and the cookie carrying them is hardened.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
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
            'Set the session cookie Secure and HttpOnly with a SameSite policy.',
        ];
    }
}
