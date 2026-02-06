<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use function extension_loaded;
use function ini_get;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

use function sprintf;

/**
 * Validates OPcache configuration for the target environment.
 *
 * Checks that OPcache is enabled and that revalidation frequency is
 * appropriate for production deployments.
 */
#[Internal]
final readonly class OpcacheCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'opcache';

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Validates OPcache is enabled and configured for the target environment';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        if (!extension_loaded('Zend OPcache')) {
            return match ($environment) {
                'production' => CheckResult::error(
                    self::CHECK_NAME,
                    'OPcache extension is not loaded',
                    [
                        'Install and enable the OPcache extension for production performance.',
                        'Add "zend_extension=opcache" to php.ini.',
                    ],
                ),
                'staging' => CheckResult::warning(
                    self::CHECK_NAME,
                    'OPcache extension is not loaded',
                    ['Install the OPcache extension to mirror production behavior.'],
                ),
                default => CheckResult::pass(
                    self::CHECK_NAME,
                    'OPcache not required in local environment',
                ),
            };
        }

        $enabled = (bool) ini_get('opcache.enable');

        if (!$enabled) {
            return match ($environment) {
                'production' => CheckResult::error(
                    self::CHECK_NAME,
                    'OPcache is installed but not enabled (opcache.enable=0)',
                    ['Set opcache.enable=1 in php.ini for production.'],
                ),
                'staging' => CheckResult::warning(
                    self::CHECK_NAME,
                    'OPcache is installed but not enabled',
                    ['Enable OPcache to mirror production behavior.'],
                ),
                default => CheckResult::pass(
                    self::CHECK_NAME,
                    'OPcache disabled in local environment (acceptable)',
                ),
            };
        }

        if ($environment === 'production' || $environment === 'staging') {
            $revalidateFreq = (int) ini_get('opcache.revalidate_freq');

            if ($revalidateFreq < 60) {
                return CheckResult::warning(
                    self::CHECK_NAME,
                    sprintf(
                        'OPcache revalidate_freq is %d seconds (recommended >= 60 for %s)',
                        $revalidateFreq,
                        $environment,
                    ),
                    [
                        'Set opcache.revalidate_freq=60 or higher in production.',
                        'Consider opcache.validate_timestamps=0 for immutable deployments.',
                    ],
                );
            }
        }

        return CheckResult::pass(
            self::CHECK_NAME,
            'OPcache is enabled and properly configured',
        );
    }
}
