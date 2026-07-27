<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\TimeTrap;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Security\AntiSpam\AntiSpamCheckInterface;
use Pulsar\Security\AntiSpam\AntiSpamCheckResult;
use Pulsar\Security\AntiSpam\AntiSpamContext;

/**
 * Pipeline adapter over {@see TimeTrapGuard}: flags submissions filled
 * implausibly fast for a human — and ONLY those.
 *
 * The timing rule (validly-signed, form-bound, non-future stamp dated less than
 * `minSeconds` before submission) lives entirely in the guard, so the pipeline
 * check and the standalone gate can never diverge. This adapter turns the guard's
 * boolean signal into an advisory spam-score contribution, and — like the
 * honeypot — fails open on everything else so a slow human never loses a
 * submission. No JavaScript is required; the stamp is rendered server-side.
 */
#[Internal(reason: 'Use AntiSpamCheckInterface')]
final readonly class TimeTrapCheck implements AntiSpamCheckInterface
{
    /** Spam-score contribution on failure (advisory, like the honeypot). */
    private const int FAIL_SCORE = 30;

    public function __construct(
        private TimeTrapGuard $guard,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'time_trap';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        $now = $context->submissionTimestamp > 0 ? $context->submissionTimestamp : null;

        if ($this->guard->isTooFast($context->formFields, $context->formId, $now)) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                self::FAIL_SCORE,
                'Form submitted implausibly fast for a human: likely automated',
            );
        }

        return AntiSpamCheckResult::pass($this->name());
    }
}
