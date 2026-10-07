<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * What a verifier found when it read an evidence register.
 *
 * These are not severity levels; they are different findings, and an assessor
 * needs them apart because each one names a different act by a different party
 * and a different thing for the operator to do next. The register used to answer
 * with a boolean, and a boolean cannot say "two records were removed from the
 * end" — it said `valid: true` for exactly that file.
 *
 * Exactly one case is admissible evidence. Everything else, including
 * {@see self::Empty} and {@see self::Unanchored}, is a register that cannot be
 * handed to an assessor as proof of anything, and saying so is the point.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum EvidenceChainVerdict
{
    /**
     * Every record the chain committed to is present, in the order it wrote
     * them, each authenticating under the key in service and each chained to the
     * one before it — and the anchor agrees about how many there should be.
     *
     * The only case that is evidence.
     */
    case Intact;

    /**
     * The register holds nothing.
     *
     * Reported apart from {@see self::Intact} because a verifier that walks zero
     * records and finds zero faults has proved nothing at all, and "valid" is the
     * wrong word for it. A fresh deployment sits here until its first collection
     * run; a deployment that has been running for a year and sits here has lost
     * its register.
     */
    case Empty;

    /**
     * A record that is present does not authenticate under the key in service,
     * or does not chain to the record before it.
     *
     * Something was rewritten in place. The record's own id is reported so the
     * operator can look at the line rather than the file.
     */
    case Modified;

    /**
     * Records the chain committed to are not there.
     *
     * Covers removal from the end — which no hash chain can see on its own and
     * which the anchor is what sees — as well as a gap in the middle, and a
     * record edited so thoroughly that it is no longer recognisable as this
     * chain's. All three are the same finding for an assessor: the register is
     * missing evidence it says it holds.
     */
    case Truncated;

    /**
     * Every record is present, and not in the order the chain wrote them.
     *
     * Includes a position that appears more than once, which is the same class of
     * event: the register's sequence is not the sequence the chain signed.
     */
    case Reordered;

    /**
     * Part of the register's own state could not be read, or its anchor is
     * missing or does not authenticate.
     *
     * A line the store could not decode is the shape a crash mid-append leaves,
     * and also the shape a record cut out with an editor leaves. An anchor that a
     * head-keeping store has lost is not an upgrade artefact — no release ever
     * wrote a register without one — so it is interference until an operator says
     * otherwise. Nothing can be concluded about the contents while this is true.
     */
    case Unreadable;

    /**
     * Nothing is wrong with the records present, and the store cannot state how
     * many records the chain has written.
     *
     * Not a fault in the register: a fault in what is holding it. Every check
     * such a store can answer was answered and held; removal from the END was not
     * checked, because it cannot be. Reporting that as {@see self::Intact} would
     * certify a property nobody looked at, which is the sentence the anchor
     * exists to stop being printed.
     *
     * It is the one inadmissible case that does NOT stop the chain appending: a
     * store that never offered the guarantee is not a register that has been
     * interfered with, and taking a deployment's evidence collection offline over
     * it would trade a stated weakness for a silent gap.
     *
     * @see \Pulsar\Compliance\Evidence\EvidenceChainHeadAware
     */
    case Unanchored;

    /**
     * The register was written under a key this process does not hold.
     *
     * A key rotation, a restore from another deployment's backup and a forged
     * register are indistinguishable from here, and no field in the file can
     * separate them, because whoever wrote the file wrote every field in it. It
     * is reported apart from {@see self::Modified} because the operator action is
     * different: put the key back in service, or account for where the register
     * came from — not "find out who edited this".
     */
    case KeyUnavailable;

    /**
     * Whether the register may be presented as evidence.
     *
     * One case, deliberately. A method rather than a list at each call site so
     * that a case added later cannot default to admissible by being forgotten.
     */
    #[NoDiscard]
    public function admissible(): bool
    {
        return $this === self::Intact;
    }
}
