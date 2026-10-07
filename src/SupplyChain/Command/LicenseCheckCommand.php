<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\SupplyChain\License\AllowedLicensesConfig;
use Pulsar\SupplyChain\License\LicenseChecker;

use function count;
use function file_get_contents;
use function is_readable;
use function is_string;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * CLI command to check dependency licenses against an allowlist.
 *
 * Returns exit code 1 if any dependency has a non-compliant or unknown license.
 */
#[Internal(reason: 'CLI command registration')]
final class LicenseCheckCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly AllowedLicensesConfig $config = new AllowedLicensesConfig(),
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'supply-chain:licenses';
        $this->description = 'Check dependency licenses against the allowlist';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $lockPath = $this->projectRoot . '/composer.lock';

        // Guard with is_readable() so file_get_contents() is only attempted on a
        // readable file — a missing/unreadable lock would otherwise emit an
        // E_WARNING before the is_string() check handled the false return.
        $lockContents = is_readable($lockPath) ? file_get_contents($lockPath) : false;

        if (!is_string($lockContents)) {
            $output->error(sprintf('Cannot read composer.lock at: %s', $lockPath));

            return ExitCode::Error->value;
        }

        /** @var array<string, mixed> $lockData */
        $lockData = json_decode($lockContents, true, 64, JSON_THROW_ON_ERROR);

        $output->info('Checking dependency licenses...');

        $checker = new LicenseChecker($this->config);
        $result = $checker->check($lockData);

        $output->writeln(sprintf('Compliant: %d packages', count($result->compliantPackages)));

        if ($result->nonCompliantPackages !== []) {
            $output->writeln('');
            $output->error('Non-compliant licenses:');

            foreach ($result->nonCompliantPackages as $pkg) {
                $output->writeln(sprintf('  - %s (%s): %s', $pkg['name'], $pkg['version'], $pkg['license']));
            }
        }

        if ($result->unknownLicensePackages !== []) {
            $output->writeln('');
            $output->warning('Unknown licenses:');

            foreach ($result->unknownLicensePackages as $pkg) {
                $output->writeln(sprintf('  - %s (%s)', $pkg['name'], $pkg['version']));
            }
        }

        if ($result->isCompliant()) {
            $output->success('All dependency licenses are compliant.');

            return ExitCode::Success->value;
        }

        $output->error(sprintf(
            'License check FAILED: %d non-compliant, %d unknown',
            count($result->nonCompliantPackages),
            count($result->unknownLicensePackages),
        ));

        return ExitCode::Error->value;
    }
}
