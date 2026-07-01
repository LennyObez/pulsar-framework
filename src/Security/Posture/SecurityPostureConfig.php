<?php

declare(strict_types=1);

namespace Pulsar\Security\Posture;

use Pulsar\Api\Api;
use Pulsar\Config\Environment;

use function in_array;

/**
 * Enforcement policy for the security-posture preflight.
 *
 * Enforcement is opt-in and ops-controlled via environment variables so the
 * default stays BC-safe (report only, never abort):
 *  - PULSAR_SECURITY_POSTURE_ENFORCE=true → blocking items abort boot.
 *  - PULSAR_SECURITY_POSTURE_STRICT=true  → DEGRADED items block too (not only FAIL).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityPostureConfig
{
    /**
     * @param list<SecurityPostureStatus> $failOn Statuses that block boot when enforcing.
     */
    public function __construct(
        public bool $enforce = false,
        public array $failOn = [SecurityPostureStatus::Fail],
    ) {}

    public static function fromEnvironment(Environment $environment): self
    {
        $enforceRaw = $environment->get('PULSAR_SECURITY_POSTURE_ENFORCE');
        $enforce = $enforceRaw === 'true' || $enforceRaw === '1';

        $strictRaw = $environment->get('PULSAR_SECURITY_POSTURE_STRICT');
        $strict = $strictRaw === 'true' || $strictRaw === '1';

        $failOn = $strict
            ? [SecurityPostureStatus::Fail, SecurityPostureStatus::Degraded]
            : [SecurityPostureStatus::Fail];

        return new self($enforce, $failOn);
    }

    public function blocks(SecurityPostureStatus $status): bool
    {
        return in_array($status, $this->failOn, true);
    }
}
