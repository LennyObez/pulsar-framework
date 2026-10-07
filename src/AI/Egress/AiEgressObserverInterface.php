<?php

declare(strict_types=1);

namespace Pulsar\AI\Egress;

use Pulsar\Api\Api;

/**
 * Told what the egress guard did, every time it does anything but pass a call
 * through untouched.
 *
 * WHY THIS IS A REQUIRED CONSTRUCTOR PARAMETER OF {@see GuardedAiClient} rather
 * than a nullable one. Under {@see \Pulsar\Security\Dlp\DlpAction::Redact} the
 * guard rewrites the caller's prompt before sending it. A redaction nobody is
 * told about is its own defect: the model answers a question the application did
 * not ask, the application reads the answer as though it had, and nothing
 * anywhere records the substitution. Making the observer optional would ship that
 * failure as the default configuration — the `null` branch is always the one
 * people take.
 *
 * A refusal is loud on its own, because it raises. It is reported here as well so
 * that one sink sees the whole picture: an assessor asking "what did this
 * deployment try to send" is asking about the refusals more than the successes.
 *
 * Implementations run inside the call they describe and before the transport for
 * a refusal. One that throws fails the call it was reporting on, which is the
 * correct direction for a guard — but it means an observer is not the place for
 * work that can fail for unrelated reasons.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface AiEgressObserverInterface
{
    /**
     * Something was found, or a call was refused.
     *
     * Called on every refusal, on every redaction, and — under
     * {@see \Pulsar\Security\Dlp\DlpAction::Alert} — on a call that was allowed
     * out *carrying* classified data, which reports
     * {@see AiEgressOutcome::Allowed}. Not called when the classifier examined
     * the payload and found nothing: that is the ordinary case, and routing it
     * here would put every clean prompt through an observer on the hot path for
     * no fact anyone needed.
     *
     * So the invariant is the useful one: if this method was not called, nothing
     * classified left the deployment on that call.
     */
    public function decided(AiEgressDecision $decision): void;
}
