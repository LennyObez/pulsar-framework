<?php

declare(strict_types=1);

namespace Pulsar\AI\Audit;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Where the auditing decorator takes the egress decision from.
 *
 * Reading is destructive, and that is the whole point of the interface. A
 * decision belongs to exactly one inference; leaving it in place would let the
 * NEXT call — one no egress control looked at, or one whose control was
 * bypassed — quietly inherit the previous call's verdict and record a redaction
 * that never happened. Taking clears the slot, so a call with nothing reported
 * reads null and is recorded as "egress decision not observed" rather than as a
 * clean pass.
 * @api
 */
#[Api(since: '1.0.0')]
interface EgressDecisionSourceInterface
{
    /**
     * Take the decision reported since the last take, clearing the slot.
     *
     * Null means no egress control reported anything for this call. It does NOT
     * mean the content was clean, and a caller that renders it as "no redaction"
     * is stating a fact nothing measured.
     */
    #[NoDiscard]
    public function takeEgressDecision(): ?EgressDecision;
}
