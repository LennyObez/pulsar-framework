<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether data in transit is encrypted on the link the deployment actually uses.
 *
 * Rests on what the database server reported negotiating, not on the sslmode
 * string in the config file. An operator can write require against a server
 * that ignores it, and a compliance report that cannot tell those apart is a
 * report about the config file.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class TransportSecurityProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.transport_security';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the database session is confirmed encrypted, and HSTS is enforced.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
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
            ObservationId::TransportSecurityEnforced,
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
            'Configure TLS on every database connection that crosses a network, then '
                . 're-run the report so the negotiated session can be confirmed rather than '
                . 'assumed.',
            'Enable HSTS with a max-age of at least one year in config/security.php.',
        ];
    }
}
