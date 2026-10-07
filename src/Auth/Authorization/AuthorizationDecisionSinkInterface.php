<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use Pulsar\Api\Api;

/**
 * Destination for the record of every decision the Gate reaches.
 *
 * This is deliberately not the application's event dispatcher. A dispatcher
 * puts every listener the application happens to have registered inside every
 * authorization decision: one that throws turns a grant into a `500`, one that
 * is slow makes every check slow, and one that asks the Gate a question
 * re-enters the decision it is being told about. A sink is a single named
 * collaborator the composition root chooses, so what runs inside a decision is
 * a wiring fact rather than whatever the application registered.
 *
 * Implementations must observe three rules, and the Gate is written so that
 * breaking any of them is contained rather than fatal:
 *
 * - **Do not throw.** The Gate catches everything this method raises and
 *   reports it at `critical`, because an audit-sink fault must not change an
 *   authorization outcome. A throwing sink is a defect, not a control flow.
 * - **Do not call back into the Gate.** A sink that asks `allows()` a question
 *   is re-entering the decision it was handed. The Gate refuses the re-entry
 *   and records the nested decision after the outer one instead of recursing,
 *   but the refusal is bounded and loud rather than free.
 * - **Be cheap, or defer.** This method runs on the authorization path of
 *   every request. {@see \Pulsar\Auth\Internal\Authorization\BufferedAuthorizationDecisionSink}
 *   — what `AuthWiring` binds — buffers the decision and writes the audit
 *   entries after the decision has been returned.
 * @api
 */
#[Api(since: '1.0.0')]
interface AuthorizationDecisionSinkInterface
{
    /**
     * Take the record of one decision the Gate has just reached.
     *
     * Called after the outcome is settled and immediately before `allows()`
     * returns it, for grants and refusals alike.
     */
    public function record(AuthorizationDecision $decision): void;
}
