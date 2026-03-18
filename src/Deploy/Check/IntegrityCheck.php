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
            // TOOL-DEP-01/02 (external audit): production fail-closed on missing
            // integrity verification. Tampered framework files in a regulated
            // deployment must trip the deploy gate, not a soft warning that
            // operators routinely ignore in the noise of a release pipeline.
            'production' => CheckResult::error(
                self::CHECK_NAME,
                'File integrity verification is disabled in production',
                [
                    'Enable integrity verification in config/integrity.php — tampered framework files',
                    'must trip the deploy gate in regulated environments (PCI Req 11, HIPAA',
                    '§164.312(c)(1), ISO 27001 A.8.13).',
                    'Run "php bin/pulsar optimize" to generate the integrity manifest before deployment.',
                ],
            ),
            'staging' => CheckResult::warning(
                self::CHECK_NAME,
                'File integrity verification is disabled',
                [
                    'Enable integrity in staging to validate the manifest workflow before production.',
                ],
            ),
            default => CheckResult::pass(
                self::CHECK_NAME,
                'Integrity verification not required in local environment',
            ),
        };
    }
}
