<?php

declare(strict_types=1);

namespace Pulsar\AI\Audit;

use Pulsar\Api\Api;

/**
 * Where an egress control reports what it decided about one outbound inference.
 *
 * The two halves of this seam exist because the audit decorator and the egress
 * control are separate objects on the same call stack, and only one of them
 * knows what happened to the content. {@see \Pulsar\AI\AiClientInterface} has no
 * parameter through which a decision could travel back up, and adding one would
 * change a published interface so that a decorator could talk to another
 * decorator. So the decision travels through a one-slot channel both are given
 * at composition time: the control reports into the sink, and the auditor takes
 * from {@see EgressDecisionSourceInterface} immediately after the inner call
 * returns or throws.
 *
 * ORDERING IS PART OF THE CONTRACT. The auditing decorator must wrap OUTSIDE the
 * egress control, so that the control has already reported by the time the
 * auditor takes. Composed the other way round, every record would say the egress
 * decision was not observed, and a refusal — the event most worth having — would
 * be recorded as a plain provider error.
 * @api
 */
#[Api(since: '1.0.0')]
interface EgressDecisionSinkInterface
{
    /**
     * Report the decision made about the call currently in flight.
     *
     * Called by the control that made the decision, before it returns or throws.
     * A second report for the same call replaces the first: the last thing the
     * control decided is what happened.
     *
     * NOT CALLED WHEN NOTHING WAS FOUND, which is deliberate on the reporting
     * side — {@see \Pulsar\AI\Egress\GuardedAiClient} keeps a clean prompt off
     * this path rather than putting every ordinary call through a channel for a
     * fact nobody needed. The invariant that leaves is the useful one and the
     * auditor depends on it: FOR A CALL A COMPOSED CONTROL EXAMINED, SILENCE HERE
     * MEANS NOTHING CLASSIFIED LEFT THE DEPLOYMENT. That is why a channel must be
     * bound only where a control actually reports into it: with a channel present
     * and nothing reported, {@see AuditingAiClient} records `no_finding`, and a
     * channel bound next to no control would turn "nobody looked" into "looked
     * and found nothing" — the laundering this whole seam exists to prevent.
     */
    public function report(EgressDecision $decision): void;
}
