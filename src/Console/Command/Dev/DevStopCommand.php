<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Dev;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Print the command to stop the Docker development environment.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class DevStopCommand extends Command
{
    public function __construct(
        private readonly DevConfig $config,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'dev:stop';
        $this->description = 'Show the command to stop the Docker development environment';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('To stop the development environment, run:');
        $output->newLine();
        $output->writeln(sprintf('  docker compose -p %s down', $this->config->projectName));

        return ExitCode::Success->value;
    }
}
