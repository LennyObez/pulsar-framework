<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Pulsar\Api\Internal;
use Pulsar\Config\AppConfig;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

/**
 * Validates that debug mode is disabled in staging and production environments.
 */
#[Internal]
final readonly class DebugModeCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'debug-mode';

    public function __construct(
        private AppConfig $appConfig,
    ) {}

    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    public function getDescription(): string
    {
        return 'Validates debug mode is disabled in staging and production';
    }

    public function check(string $environment): CheckResult
    {
        if (!$this->appConfig->debug) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'Debug mode is disabled',
            );
        }

        return match ($environment) {
            'production' => CheckResult::error(
                self::CHECK_NAME,
                'Debug mode is enabled in production',
                [
                    'Set APP_DEBUG=false in your environment configuration.',
                    'Debug mode exposes sensitive information and degrades performance.',
                ],
            ),
            'staging' => CheckResult::warning(
                self::CHECK_NAME,
                'Debug mode is enabled in staging',
                [
                    'Consider disabling debug mode in staging to match production.',
                    'Set APP_DEBUG=false in your environment configuration.',
                ],
            ),
            default => CheckResult::pass(
                self::CHECK_NAME,
                'Debug mode is acceptable in local environment',
            ),
        };
    }
}
