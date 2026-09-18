<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether data leaving this process is encrypted, on every link it leaves by.
 *
 * TWO LINKS, BOTH REQUIRED, AND THE SECOND ONE IS THE REPAIR. This probe used to
 * require the database session alone. A deployment reaching its database over a
 * unix socket, or using SQLite, or holding no database at all, therefore had no
 * subject for the only fact the control rested on — and HIPAA 164.312(e)(1),
 * Transmission Security, RETIRED itself to NotApplicable on a deployment that was
 * serving ePHI over HTTP. The control could not be Unsatisfied. That is the same
 * class of broken instrument as one that cannot be Satisfied, and it was the more
 * dangerous half, because the report said "not applicable" about a link that was
 * carrying traffic while the assessor read it.
 *
 * The HTTP transport is the link every deployment that serves requests has. It is
 * now required, so the control always has something to be about, and the outcome
 * on a socket-only deployment is an honest gap instead of a disappearance.
 *
 * WHAT IS DELIBERATELY NOT HERE: a MEASURED fact about HTTP. `transport_security_
 * enforced` is a config read, graded {@see \Pulsar\Compliance\Control\ObservationGrade::Declared},
 * and it stays one. TLS for inbound requests is normally terminated in a proxy
 * this process cannot see, so an observer claiming to have measured it would be
 * a resolution wearing a Measured badge — the exact defect ADR-0061 exists to
 * remove, reintroduced one estate over. Transmission security has to stop
 * evaporating; it does not have to turn green. An honest Unsatisfied is the goal
 * and is what a deployment without a networked, TLS-negotiating database now gets.
 *
 * The estate the two controls declare is {@see \Pulsar\Compliance\Control\ControlSubject::DataInTransit},
 * which contains both links — see {@see \Pulsar\Compliance\Control\ControlSubject::covers()}
 * for why that relation is enumerated rather than inferred. The database fact is
 * what gives the control an honest route to Satisfied: it rests on what the server
 * reported negotiating, not on the sslmode string in the config file, and an
 * operator can write `require` against a server that ignores it.
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
        return 'Whether the database session is confirmed encrypted, and HSTS is enforced on the '
            . 'transport that carries requests.';
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
            'Enable HSTS with a max-age of at least one year in config/security.php. This is '
                . 'read from configuration, not measured: it is what the deployment asks the '
                . 'browser to do, and no code in this process can confirm the TLS a proxy '
                . 'terminated in front of it.',
        ];
    }
}
