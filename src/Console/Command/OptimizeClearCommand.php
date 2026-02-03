<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Pulsar\Api\Internal;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

/**
 * Removes all framework cache files.
 *
 * Usage: optimize:clear
 */
#[Internal]
final class OptimizeClearCommand extends Command
{
    public function __construct(
        private readonly FrameworkCache $frameworkCache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'optimize:clear';
        $this->description = 'Clear all framework cache files';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->frameworkCache->clear();

        $output->writeln('Framework cache cleared.');

        return ExitCode::Success->value;
    }
}
