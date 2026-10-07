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
 *
 * THE ORDER OF THE CASES IS NOT A RANK. Each names a different KIND of thing the
 * assessor touched — a behaviour, a platform, a binding, a setting, an operator —
 * and the only ordering that exists anywhere is {@see provesBehaviour()}, which
 * sorts them into exactly two groups. {@see Available} is written second because
 * the facts that reach it were graded {@see Measured} until recently and a reader
 * needs the two side by side, not because it is nearly as good; on the one axis
 * that decides anything it sits with Declared and Asserted.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum ObservationGrade: string
{
    /** The behaviour was exercised: an algorithm ran, an HMAC chain verified, a query returned. */
    case Measured = 'measured';

    /**
     * The PLATFORM offers a primitive, and nothing in this deployment used it.
     *
     * `extension_loaded('sodium')` is the whole shape of it: a question answered
     * by the build PHP was compiled with, identically on a deployment that
     * encrypts every field and on one that encrypts nothing. Read the three
     * against each other, because the differences are the reason this case had to
     * exist rather than be folded into one of them:
     *
     *  - {@see Measured} says something in THIS deployment was put through its
     *    work and answered. Available says nothing was.
     *  - {@see Resolved} says which concrete class will serve requests. Available
     *    names no class, because an extension is not a binding; a deployment can
     *    have libsodium loaded and no cipher suite bound at all.
     *  - {@see Declared} says an operator asked for something in configuration.
     *    Available is not asked for and cannot be switched off in a config file.
     *
     * WHY A FIFTH CASE, AND THE HONEST WEAKNESS OF THE ARGUMENT. Review argued
     * against adding it: no code branches on the difference, because the decision
     * table reads only {@see provesBehaviour()}, and that is true — Available and
     * Resolved are interchangeable to every consumer in the tree. The case is not
     * defended on the table. It is defended on the reader, who is an assessor: the
     * report prints this word beside every fact, and `cryptographic_capability
     * (resolved)` would tell that reader a class was resolved when none was, which
     * is the same species of false badge as the `(measured)` this case exists to
     * take away. And the weak half, said plainly: exactly ONE fact in the
     * vocabulary reaches this grade today — {@see ObservationId::CryptographicCapability}
     * — so the case earns its place on the vocabulary having no honest slot for
     * "the platform offers it", never on breadth of use.
     *
     * Its material is {@see PlatformCapability}, sealed exactly as
     * {@see Measurement} is; see {@see Observation::available()}.
     */
    case Available = 'available';

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
     * {@see Available} is weaker still and was added for the same reason, one
     * lookup further out again: an extension being loaded is a fact about the
     * PLATFORM, so it is identical on a deployment that encrypts everything and
     * on one that encrypts nothing, and a grade whose value cannot differ between
     * those two cannot be evidence about either. It was graded Measured until
     * `runtime.sodium_extension` was read for what it does rather than for where
     * it is published, and on that grading nine controls across seven frameworks
     * reached Satisfied on `extension_loaded('sodium')` alone.
     *
     * What Resolved is FOR is unchanged and worth keeping: a report that names
     * `AuditSinkInterface -> AuditFileSink` tells an assessor something a report
     * saying "audit logging: not observed" does not, and it tells them which
     * class to go and look at. It is context, printed with its grade beside it,
     * and it is never the reason a control holds. The same is true of Available:
     * "ext-sodium is present" is worth printing and is not a control.
     *
     * The cost is paid openly rather than engineered around: controls that were
     * Satisfied on resolved identity or on platform availability alone are now
     * Unsatisfied, and their findings say the deployment shows which classes are
     * wired and what the platform offers rather than that the control runs.
     * Inventing a measurement to keep one of them green would be the same defect
     * wearing this method's approval.
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
