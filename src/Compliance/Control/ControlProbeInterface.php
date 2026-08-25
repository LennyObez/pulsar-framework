<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Declares what one control needs. It does not decide anything.
 *
 * Probes are named, shared objects rather than closures on a mapping: ISO 27001
 * A.8.15, PCI Req 10.2 and NIST DE.AE all want the same fact about audit
 * logging, and before this contract each of the three stated it in different
 * prose and could disagree with the others. A shared probe makes them cite one
 * measurement.
 *
 * A probe used to be handed the evidence and asked for a verdict. That put the
 * outcome in the probe's hands, and review showed what that costs: a probe could
 * return Satisfied citing observations it had minted itself. The method is now
 * {@see requirement()}, which returns ids and prose — no evidence, no outcome —
 * and {@see ProbeVerdict::reach()} decides against the evidence set the engine
 * gathered. There is no longer any expression a probe can write that names a
 * {@see ControlOutcome}.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface ControlProbeInterface
{
    /**
     * Stable identifier, e.g. 'probe.pan_at_rest'. Printed beside every outcome
     * so the report names what looked, not only what it concluded.
     *
     * @return non-empty-string
     */
    #[NoDiscard]
    public function id(): string;

    /**
     * What this probe looks for, in one sentence, for the evidence column.
     *
     * A statement about the probe, never about the deployment: it is printed
     * whatever the outcome, so a sentence claiming the control holds would be
     * false on every failing finding that carries it.
     */
    #[NoDiscard]
    public function describe(): string;

    /**
     * The facts this control rests on, and how they combine.
     *
     * Takes no evidence, so a probe cannot read a deployment; returns no outcome,
     * so a probe cannot state one. It names {@see ObservationId}s out of a closed
     * vocabulary, so it cannot name a fact nobody gathers either.
     */
    #[NoDiscard]
    public function requirement(): ControlRequirement;
}
