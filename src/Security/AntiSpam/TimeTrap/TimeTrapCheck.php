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
 * Rejects submissions whose form-fill timing is implausible for a human.
 *
 * Reads the server-signed render stamp the {@see TimeTrapRenderer} embedded as a
 * hidden field and computes how long the form took to submit. A submission sent
 * faster than `minSeconds` (a bot posting on page load) or later than
 * `maxSeconds` (a stale, likely-replayed page) is flagged. The stamp is
 * tamper-proof: its timestamp and form binding are HMAC-signed, so a client can
 * neither backdate it nor replay it against another form.
 *
 * Unlike the managed challenge this needs NO JavaScript — the stamp is rendered
 * server-side — so it closes the form-timing gap for no-JS clients.
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
        private int $maxSeconds = 3600,
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

        if (!is_string($value) || $value === '') {
            return AntiSpamCheckResult::fail(
                $this->name(),
                self::FAIL_SCORE,
                'Time-trap stamp is missing: likely automated submission',
            );
        }

        $token = $this->service->parse($value);

        if ($token === null) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                self::FAIL_SCORE,
                'Time-trap stamp signature is invalid: forged or corrupted stamp',
            );
        }

        // The stamp must have been minted for this exact form/route. Constant-time
        // compare so a mismatch cannot be probed via timing.
        if (!hash_equals($token->formId, $context->formId)) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                self::FAIL_SCORE,
                'Time-trap stamp is bound to a different form: replayed stamp',
            );
        }

        $now = $context->submissionTimestamp > 0 ? $context->submissionTimestamp : time();
        $age = $now - $token->issuedAt;

        // A stamp dated in the future beyond the skew tolerance signals a forged
        // or replayed timestamp.
        if ($age < -self::SKEW_TOLERANCE_SECONDS) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                self::FAIL_SCORE,
                'Time-trap stamp is dated in the future: forged timestamp',
            );
        }

        if ($age < $this->minSeconds) {
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

        if ($age > $this->maxSeconds) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                self::FAIL_SCORE,
                sprintf(
                    'Form submitted after a stale delay (%ds > %ds maximum): likely replayed page',
                    $age,
                    $this->maxSeconds,
                ),
            );
        }

        return AntiSpamCheckResult::pass($this->name());
    }
}
