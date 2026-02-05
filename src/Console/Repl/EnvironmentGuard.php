<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;

/**
 * Guards REPL access based on environment, configuration, and flags.
 *
 * CI environments are always blocked. Production requires explicit config
 * enablement plus the --i-know-what-im-doing flag. All other environments
 * require explicit config enablement (secure-by-default).
 */
#[Api(since: '1.0.0')]
final readonly class EnvironmentGuard
{
    public function __construct(
        private EnvironmentMode $mode,
        private Environment $environment,
        private bool $configEnabled,
    ) {}

    /**
     * Determine whether the REPL session is allowed to start.
     */
    #[NoDiscard]
    public function canStart(bool $forceFlag = false): GuardResult
    {
        // CI is always blocked regardless of any config
        $ciValue = $this->environment->get('CI');
        if ($ciValue !== null && $ciValue !== '' && $ciValue !== '0' && $ciValue !== 'false') {
            return new GuardResult(
                allowed: false,
                reason: 'REPL is not available in CI environments.',
            );
        }

        // All environments require config enabled
        if (!$this->configEnabled) {
            return new GuardResult(
                allowed: false,
                reason: 'REPL is not enabled. Set REPL_ENABLED=true in your environment or config/repl.php.',
            );
        }

        // Production additionally requires --i-know-what-im-doing
        if ($this->mode === EnvironmentMode::Production) {
            if (!$forceFlag) {
                return new GuardResult(
                    allowed: false,
                    reason: 'REPL in production requires the --i-know-what-im-doing flag.',
                );
            }

            return new GuardResult(
                allowed: true,
                reason: 'Production REPL override acknowledged.',
                isProductionOverride: true,
            );
        }

        return new GuardResult(
            allowed: true,
            reason: 'REPL access granted.',
        );
    }
}
