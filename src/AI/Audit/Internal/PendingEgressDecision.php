<?php

declare(strict_types=1);

namespace Pulsar\AI\Audit\Internal;

use NoDiscard;
use Override;
use Pulsar\AI\Audit\EgressDecision;
use Pulsar\AI\Audit\EgressDecisionSinkInterface;
use Pulsar\AI\Audit\EgressDecisionSourceInterface;
use Pulsar\Api\Internal;

/**
 * The one-slot channel between an egress control and the auditing decorator.
 *
 * Deliberately mutable, and deliberately the ONLY mutable object in this
 * subsystem: a decision has to survive the return from the inner call and reach
 * the frame above it, and there is nowhere else to put it — the inner call's
 * return type belongs to the caller, not to a decorator.
 *
 * The slot holds at most one decision. {@see takeEgressDecision()} empties it, so
 * a decision can be attributed to exactly one inference and never leaks forward
 * onto a later call that no control examined.
 *
 * Not fiber-keyed, and the reasoning is the same as
 * {@see \Pulsar\Security\Audit\AuditLogger}'s chain-advance flag. Under ADR-0071
 * the AI path is ordinary synchronous code: an egress control reports and the
 * auditor takes within one uninterrupted call stack, with no suspension point
 * between them. A second concurrent inference sharing this instance would be a
 * defect in the composition, not a case to be papered over with a per-fiber map
 * that would make the defect invisible.
 */
#[Internal(reason: 'Composition detail of the AI audit seam; consumers depend on the two interfaces')]
final class PendingEgressDecision implements EgressDecisionSinkInterface, EgressDecisionSourceInterface
{
    private ?EgressDecision $decision = null;

    #[Override]
    public function report(EgressDecision $decision): void
    {
        $this->decision = $decision;
    }

    #[NoDiscard]
    #[Override]
    public function takeEgressDecision(): ?EgressDecision
    {
        $taken = $this->decision;
        $this->decision = null;

        return $taken;
    }
}
