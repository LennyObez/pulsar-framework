<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Pulsar\Api\Internal;
use Pulsar\Config\DeployConfig;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

use function sprintf;

/**
 * Validates HTTP/3 and Alt-Svc configuration when opted in.
 *
 * If HTTP/3 is enabled in the deploy configuration, validates that the
 * Alt-Svc max-age is set to a reasonable value. If HTTP/3 is not enabled,
 * the check passes silently (opt-in feature).
 */
#[Internal]
final readonly class Http3ReadinessCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'http3-readiness';

    /** Minimum recommended Alt-Svc max-age in seconds (1 hour). */
    private const int MIN_ALT_SVC_MAX_AGE = 3600;

    public function __construct(
        private DeployConfig $deployConfig,
    ) {}

    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    public function getDescription(): string
    {
        return 'Validates HTTP/3 Alt-Svc configuration when opted in';
    }

    public function check(string $environment): CheckResult
    {
        if (!$this->deployConfig->http3Enabled) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'HTTP/3 is not enabled (opt-in feature, skipped)',
            );
        }

        $maxAge = $this->deployConfig->http3AltSvcMaxAge;

        if ($maxAge < self::MIN_ALT_SVC_MAX_AGE) {
            return match ($environment) {
                'production' => CheckResult::warning(
                    self::CHECK_NAME,
                    sprintf(
                        'HTTP/3 Alt-Svc max-age is %d seconds (recommended >= %d)',
                        $maxAge,
                        self::MIN_ALT_SVC_MAX_AGE,
                    ),
                    [
                        sprintf(
                            'Increase http3.alt_svc_max_age to at least %d seconds in config/deploy.php.',
                            self::MIN_ALT_SVC_MAX_AGE,
                        ),
                        'A low max-age causes frequent Alt-Svc re-advertisement overhead.',
                    ],
                ),
                'staging' => CheckResult::warning(
                    self::CHECK_NAME,
                    sprintf('HTTP/3 Alt-Svc max-age is %d seconds (low)', $maxAge),
                    ['Consider increasing Alt-Svc max-age for staging to mirror production.'],
                ),
                default => CheckResult::pass(
                    self::CHECK_NAME,
                    'HTTP/3 Alt-Svc max-age not enforced in local environment',
                ),
            };
        }

        return CheckResult::pass(
            self::CHECK_NAME,
            sprintf('HTTP/3 is enabled with Alt-Svc max-age of %d seconds', $maxAge),
        );
    }
}
