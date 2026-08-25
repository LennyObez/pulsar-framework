<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * How a fact about the deployment was obtained.
 *
 * The grade is the load-bearing part of the contract. ADR-0041's defect was a
 * control that reported itself implemented on the strength of code existing;
 * the general form of that defect is a control satisfied by evidence that only
 * records what someone WANTED, not what the deployment DOES. The grade makes
 * that distinction structural rather than a matter of care.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum ObservationGrade: string
{
    /** The behaviour was exercised: an algorithm ran, an HMAC chain verified, a query returned. */
    case Measured = 'measured';

    /**
     * The concrete implementation that will serve requests was resolved and named.
     *
     * Useful context in a report and NOT proof of anything. See
     * {@see provesBehaviour()} for the argument.
     */
    case Resolved = 'resolved';

    /** A configuration value was read. Records what was requested, never what happened. */
    case Declared = 'declared';

    /** The operator asserted a fact about the deployment that no code can observe. */
    case Asserted = 'asserted';

    /**
     * Whether the grade evidences behaviour rather than intent.
     *
     * ONLY MEASURED. Resolved used to qualify too, and that was ADR-0041's defect
     * restated in the vocabulary built to forbid it. Follow what Resolved actually
     * carries: {@see \Pulsar\Compliance\Control\ContractResolution::discharged()}
     * asks which class is bound to a contract and whether that class is on an
     * allow-list. "Which class is bound" is "the class exists" with one extra
     * lookup, and this repository proves the gap is not theoretical —
     * TokenStoreInterface resolves to the accepted, durable DatabaseTokenStore
     * against a database holding no `token_vault` table, so the vault throws on
     * its first use while the resolution reads clean.
     *
     * What Resolved is FOR is unchanged and worth keeping: a report that names
     * `AuditSinkInterface -> AuditFileSink` tells an assessor something a report
     * saying "audit logging: not observed" does not, and it tells them which
     * class to go and look at. It is context, printed with its grade beside it,
     * and it is never the reason a control holds.
     *
     * The cost is paid openly rather than engineered around: controls that were
     * Satisfied on resolved identity alone are now Unsatisfied, and their
     * findings say the deployment shows which classes are wired rather than that
     * the control runs. Inventing a measurement to keep one of them green would
     * be the same defect wearing this method's approval.
     *
     * Note what is absent from the whole enum: there is no grade for "the binding
     * exists". Binding presence is not expressible as evidence at all.
     */
    #[NoDiscard]
    public function provesBehaviour(): bool
    {
        return $this === self::Measured;
    }
}
