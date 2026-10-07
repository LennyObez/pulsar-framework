<?php

declare(strict_types=1);

namespace Pulsar\Security\Posture;

use Pulsar\Api\Api;

/**
 * A single evaluated security control: what was checked, the outcome, why, and
 * how to fix it when not {@see SecurityPostureStatus::Ok}.
 *
 * `Ok` answers one question — "should this deployment be stopped over it?" — and
 * outside production the answer is no for several weaknesses that plainly exist:
 * debug mode on, HSTS unenforced, a session cookie without the Secure flag. The
 * status was the only thing recorded, so a reader downstream had no way to tell
 * "the control holds" from "the control does not hold and we are not failing the
 * build over it". The compliance evidence gatherer read `Ok` as the former and
 * published `debug_mode_disabled / present: true` beside the reason "Debug mode
 * is enabled".
 *
 * {@see relaxed()} records the difference. It stays `Ok`, so nothing that gates
 * on status changes behaviour, and it carries `$relaxed = true`, so a consumer
 * asking the other question gets the other answer.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityPostureItem
{
    /**
     * @param bool $relaxed Whether this item is `Ok` because the environment does not
     *        warrant failing over it, rather than because the control holds. Always
     *        false for {@see fail()} and {@see degraded()}, which already say the
     *        control does not hold
     */
    public function __construct(
        public string $name,
        public SecurityPostureStatus $status,
        public string $reason,
        public string $fix = '',
        public bool $relaxed = false,
    ) {}

    public static function ok(string $name, string $reason): self
    {
        return new self($name, SecurityPostureStatus::Ok, $reason);
    }

    /**
     * The control does NOT hold, and this deployment is not being failed over it
     * because it is not production.
     *
     * Not a fourth status: `security:check` and `deploy:check` gate on severity,
     * and promoting a development convenience to Degraded would make every local
     * run print warnings an operator can do nothing useful about. What it changes
     * is what a CONSUMER OF EVIDENCE sees — see
     * {@see \Pulsar\Compliance\Evidence\ControlEvidenceGatherer}, which now reports
     * these items as unmet, because a deployment with debug mode on has not
     * satisfied "debug mode is disabled" no matter which environment it runs in.
     *
     * @param string $fix What would make the control actually hold, so the report
     *                    can say it rather than leaving the reader to infer it
     */
    public static function relaxed(string $name, string $reason, string $fix): self
    {
        return new self($name, SecurityPostureStatus::Ok, $reason, $fix, true);
    }

    public static function degraded(string $name, string $reason, string $fix): self
    {
        return new self($name, SecurityPostureStatus::Degraded, $reason, $fix);
    }

    public static function fail(string $name, string $reason, string $fix): self
    {
        return new self($name, SecurityPostureStatus::Fail, $reason, $fix);
    }
}
