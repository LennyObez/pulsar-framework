<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

use function sprintf;

/**
 * Validates that file integrity verification is enabled.
 *
 * Integrity checking detects unauthorized modifications to source files,
 * configuration, and binaries: a critical control for regulated environments.
 */
#[Internal]
final readonly class IntegrityCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'integrity';

    public function __construct(
        private IntegrityConfig $integrityConfig,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Validates file integrity verification is enabled';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        if ($this->integrityConfig->enabled) {
            return CheckResult::pass(
                self::CHECK_NAME,
                sprintf('Integrity verification is enabled (mode: %s)', $this->integrityConfig->mode->value),
            );
        }

        return match ($environment) {
            'production' => CheckResult::warning(
                self::CHECK_NAME,
                'File integrity verification is disabled',
                [
                    'Enable integrity verification in config/integrity.php for tamper detection.',
                    'Run "php bin/pulsar optimize" to generate the integrity manifest.',
                    'Integrity verification is recommended for regulated environments.',
                ],
            ),
            'staging' => CheckResult::warning(
                self::CHECK_NAME,
                'File integrity verification is disabled',
                [
                    'Enable integrity in staging to validate the manifest workflow.',
                ],
            ),
            default => CheckResult::pass(
                self::CHECK_NAME,
                'Integrity verification not required in local environment',
            ),
        };
    }
}
