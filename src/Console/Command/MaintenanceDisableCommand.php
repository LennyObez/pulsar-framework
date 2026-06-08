<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Deploy\MaintenanceMode;

/**
 * Disable maintenance mode.
 *
 * Usage: maintenance:disable
 */
#[Internal]
final class MaintenanceDisableCommand extends Command
{
    public function __construct(
        private readonly MaintenanceMode $maintenanceMode,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'maintenance:disable';
        $this->description = 'Disable maintenance mode and resume normal operation';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->maintenanceMode->isActive()) {
            $output->info('Maintenance mode is not active.');
            return ExitCode::Success->value;
        }

        $this->maintenanceMode->disable();

        $output->success('Maintenance mode disabled. Application is live.');

        return ExitCode::Success->value;
    }
}
