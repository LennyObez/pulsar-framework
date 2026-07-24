<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\TimeTrap;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Security\AntiSpam\AntiSpamCheckInterface;
use Pulsar\Security\AntiSpam\AntiSpamCheckResult;
use Pulsar\Security\AntiSpam\AntiSpamContext;

use function hash_equals;
use function is_string;
use function sprintf;
use function time;

/**
 * Flags submissions filled implausibly fast for a human — and ONLY those.
 *
 * Reads the server-signed render stamp the {@see TimeTrapRenderer} embedded as a
 * hidden field. When the stamp is validly signed, bound to this form, and dated
 * less than `minSeconds` before submission (a bot posting on page load), the
 * check fails — the single unambiguous bot signal.
 *
 * Everything else FAILS OPEN and passes: a missing, malformed, tampered,
 * wrong-form, future-dated, or stale stamp never blocks. Those cases are covered
 * by the honeypot, managed challenge and rate limiter, and a slow human with a
 * stale tab must never lose their submission ("zero lost lead"). No JavaScript is
 * required — the stamp is rendered server-side — so this closes the form-timing
 * gap for no-JS clients.
 */
#[Internal(reason: 'Use AntiSpamCheckInterface')]
final readonly class TimeTrapCheck implements AntiSpamCheckInterface
{
    /** Spam-score contribution on failure (advisory, like the honeypot). */
    private const int FAIL_SCORE = 30;

    /** Negative-skew tolerance in seconds, matching ManagedChallengeService. */
    private const int SKEW_TOLERANCE_SECONDS = 5;

    public function __construct(
        private TimeTrapService $service,
        private string $fieldName = 'pulsar-form-ts',
        private int $minSeconds = 3,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'time_trap';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        /** @var mixed $value */
        $value = $context->formFields[$this->fieldName] ?? null;

        // Missing stamp: no evidence either way — fail open.
        if (!is_string($value) || $value === '') {
            return AntiSpamCheckResult::pass($this->name());
        }

        $token = $this->service->parse($value);

        // Invalid signature, malformed, or minted for a different form: a client
        // cannot forge positive "too fast" evidence, so treat these as no signal
        // and fail open rather than risk blocking a legitimate submission.
        if ($token === null || !hash_equals($token->formId, $context->formId)) {
            return AntiSpamCheckResult::pass($this->name());
        }

        $now = $context->submissionTimestamp > 0 ? $context->submissionTimestamp : time();
        $age = $now - $token->issuedAt;

        // The only blocking case: a genuine (non-future) submission faster than a
        // human could plausibly fill the form. A stamp dated further in the future
        // than the skew tolerance is a clock anomaly, not evidence — fail open. A
        // stale stamp (age >= minSeconds, however old) is a slow human — fail open.
        if ($age >= -self::SKEW_TOLERANCE_SECONDS && $age < $this->minSeconds) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                self::FAIL_SCORE,
                sprintf(
                    'Form submitted implausibly fast (%ds < %ds minimum): likely automated',
                    $age,
                    $this->minSeconds,
                ),
            );
        }

        return AntiSpamCheckResult::pass($this->name());
    }
}
