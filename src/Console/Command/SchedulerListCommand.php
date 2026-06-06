<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Scheduler\JobRegistry;

use function sprintf;

/**
 * List all registered scheduled jobs.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class SchedulerListCommand extends Command
{
    public function __construct(
        private readonly JobRegistry $registry,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'scheduler:list';
        $this->description = 'List all registered scheduled jobs';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobs = $this->registry->all();

        if ($jobs === []) {
            $output->info('No scheduled jobs registered.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Registered jobs (%d):', $this->registry->count()));
        $output->newLine();

        foreach ($jobs as $job) {
            $schedule = $job->getSchedule();
            $output->writeln(sprintf(
                '  %s  [%s]  %s',
                $job->getName(),
                $schedule->expression,
                $job->getDescription(),
            ));
        }

        $output->newLine();

        return ExitCode::Success->value;
    }
}
