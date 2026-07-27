<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\TimeTrap;

use NoDiscard;
use Pulsar\Api\Api;

use function hash_equals;
use function is_string;
use function time;

/**
 * Standalone form-timing gate — usable WITHOUT the anti-spam pipeline.
 *
 * A lightweight form (a newsletter opt-in, a one-field contact box) can adopt the
 * no-JS time-trap defence directly: render the stamp with the {@see TimeTrapRenderer}
 * / `@timetrap` directive (same configured field name), then on submit call
 * {@see evaluate()} and act on the {@see TimeTrapDecision}. No AntiSpamPipeline,
 * no scoring plumbing.
 *
 * The gate owns the single timing rule (shared with {@see TimeTrapCheck} so the
 * pipeline and the standalone path can never diverge) and applies the configured
 * {@see TimeTrapFailurePolicy}. Only a validly-signed, form-bound, non-future
 * stamp dated less than `minSeconds` before submission counts as "too fast";
 * every other case fails open, so a slow human never loses a submission.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class TimeTrapGuard
{
    /** Negative-skew tolerance in seconds, matching ManagedChallengeService. */
    private const int SKEW_TOLERANCE_SECONDS = 5;

    public function __construct(
        private TimeTrapService $service,
        private string $fieldName = 'pulsar-form-ts',
        private int $minSeconds = 3,
        private TimeTrapFailurePolicy $failurePolicy = TimeTrapFailurePolicy::ScoreOnly,
    ) {}

    /**
     * Evaluate a submission and return the action to take under the configured
     * failure policy.
     *
     * @param array<string, mixed> $formFields The submitted fields (e.g. the
     *        parsed request body); the signed stamp is read from the configured
     *        field name.
     * @param string $formId Must match the id the stamp was minted for, or the
     *        stamp is treated as no signal (fail open).
     * @param int|null $now Submission time; defaults to the current time.
     */
    #[NoDiscard]
    public function evaluate(array $formFields, string $formId = '', ?int $now = null): TimeTrapDecision
    {
        if (!$this->isTooFast($formFields, $formId, $now)) {
            return TimeTrapDecision::Accept;
        }

        return match ($this->failurePolicy) {
            TimeTrapFailurePolicy::SilentAccept => TimeTrapDecision::SilentlyDrop,
            TimeTrapFailurePolicy::HardReject => TimeTrapDecision::Reject,
            // Advisory only outside a pipeline: never block on the timing signal.
            TimeTrapFailurePolicy::ScoreOnly => TimeTrapDecision::Accept,
        };
    }

    /**
     * Whether the submission was filled implausibly fast for a human — the single
     * unambiguous bot signal. This is the shared timing rule; the pipeline
     * {@see TimeTrapCheck} and {@see evaluate()} both defer to it.
     *
     * Fails open (returns false) for a missing, malformed, tampered, wrong-form,
     * or future-dated stamp, and for a stale stamp (a slow human).
     *
     * @param array<string, mixed> $formFields
     */
    #[NoDiscard]
    public function isTooFast(array $formFields, string $formId = '', ?int $now = null): bool
    {
        /** @var mixed $value */
        $value = $formFields[$this->fieldName] ?? null;

        if (!is_string($value) || $value === '') {
            return false;
        }

        $token = $this->service->parse($value);

        // A client cannot forge positive "too fast" evidence: an invalid,
        // malformed, or cross-form stamp is no signal, not a failure.
        if ($token === null || !hash_equals($token->formId, $formId)) {
            return false;
        }

        $now ??= time();
        $age = $now - $token->issuedAt;

        // Only a genuine (non-future) submission faster than a human could
        // plausibly fill the form. A future stamp beyond the skew tolerance is a
        // clock anomaly; a stale stamp is a slow human — both fail open.
        return $age >= -self::SKEW_TOLERANCE_SECONDS && $age < $this->minSeconds;
    }

    /**
     * The configured hidden-field name carrying the signed render stamp — the
     * same name the renderer emits, so a caller can wire its form end to end.
     */
    #[NoDiscard]
    public function fieldName(): string
    {
        return $this->fieldName;
    }
}
