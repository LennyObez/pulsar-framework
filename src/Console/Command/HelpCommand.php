<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Application;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

/**
 * Displays the main help screen.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class HelpCommand extends Command
{
    public function __construct(
        private readonly Application $application,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'help';
        $this->description = 'Display the help screen';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->application->renderHelp($output);

        return ExitCode::Success->value;
    }
}
