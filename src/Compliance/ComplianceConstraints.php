<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use Pulsar\Api\Api;

/**
 * Which of the numeric compliance controls at least one enabled framework
 * actually constrains — as opposed to the {@see ComplianceProfileResolver}'s
 * baseline default.
 *
 * The resolved {@see ComplianceProfile} always carries a value for every field
 * (e.g. a 900 s session idle timeout, an 8-char password floor) even when NO
 * enabled framework mandates that control: the resolver seeds each numeric
 * control with a sensible default. That default is fine for reporting, but it
 * must NOT be enforced as though a framework required it — doing so would tighten
 * (or, in strict mode, fail-close) an operator's config over a limit that none of
 * their enabled frameworks impose, and falsely attribute it to those frameworks.
 *
 * Enforcement therefore consults these flags and acts on a numeric control ONLY
 * when its flag is true. The boolean controls (encryption, MFA scope, consent…)
 * need no flag here: the resolver already resolves them to their weakest value
 * (false / 'none') when unconstrained, so enforcing that value is a natural no-op.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ComplianceConstraints
{
    public function __construct(
        public bool $sessionIdleTimeout = false,
        public bool $passwordMinLength = false,
        public bool $breachNotificationHours = false,
        public bool $auditRetentionDays = false,
        public bool $dataRetentionDays = false,
    ) {}
}
