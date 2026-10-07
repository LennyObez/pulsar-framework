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
 *  - PULSAR_SECURITY_POSTURE_LOG_AT_BOOT=true|false → force the boot-time advisory
 *    log on/off (default: on outside production, off in production).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityPostureConfig
{
    /**
     * @param list<SecurityPostureStatus> $failOn Statuses that block boot when enforcing.
     * @param bool $logAtBoot Emit the advisory posture log line(s) at boot. Security
     *        posture is a property of the CONFIGURATION -- it cannot change between
     *        requests -- so under a per-request SAPI (PHP-FPM: one boot per request)
     *        logging it every boot floods the log with an unchanging state. It is
     *        therefore off in production by default; the posture is still surfaced
     *        through the /health endpoint (SecurityPostureHealthCheck), the
     *        `security:check` command, and boot enforcement. On, it uses `warning`
     *        (a deliberate config state), never `error`.
     */
    public function __construct(
        public bool $enforce = false,
        public array $failOn = [SecurityPostureStatus::Fail],
        public bool $logAtBoot = true,
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

        // Default: log at boot everywhere EXCEPT production, where boot==request
        // under PHP-FPM would flood the log with an unchanging config state. An
        // explicit env value overrides in either direction.
        $isProduction = ($environment->get('APP_ENV') ?? 'local') === 'production';
        $logAtBootRaw = $environment->get('PULSAR_SECURITY_POSTURE_LOG_AT_BOOT');
        $logAtBoot = match ($logAtBootRaw) {
            'true', '1' => true,
            'false', '0' => false,
            default => !$isProduction,
        };

        return new self($enforce, $failOn, $logAtBoot);
    }

    public function blocks(SecurityPostureStatus $status): bool
    {
        return in_array($status, $this->failOn, true);
    }
}
