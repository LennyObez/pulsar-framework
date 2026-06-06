<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\FrameworkCacheInterface;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

/**
 * Removes all framework cache files.
 *
 * Usage: optimize:clear
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class OptimizeClearCommand extends Command
{
    public function __construct(
        private readonly ?FrameworkCacheInterface $frameworkCache = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'optimize:clear';
        $this->description = 'Clear all framework cache files';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->frameworkCache === null) {
            $output->errorln('PULSAR_MASTER_KEY is required for FrameworkCache integrity (HMAC/encryption).');
            $output->errorln('Set it via shell environment or a .env file.');
            $output->errorln('Generate one with: php bin/pulsar key:generate');

            return ExitCode::Error->value;
        }

        $this->frameworkCache->clear();

        $output->writeln('Framework cache cleared.');

        return ExitCode::Success->value;
    }
}
