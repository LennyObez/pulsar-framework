<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Scheduler\Scheduler;

use function sprintf;

/**
 * Run all due scheduled jobs.
 */
final class SchedulerTickCommand extends Command
{
    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('scheduler:tick')
            ->setDescription('Run all due scheduled jobs');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->scheduler->tick();

        if ($result->jobsDue === 0) {
            $output->info('No jobs due at this time.');
            return ExitCode::Success->value;
        }

        foreach ($result->results as $jobResult) {
            $status = $jobResult->status->value;
            $output->writeln(sprintf(
                '  [%s] %s (%.1fms)',
                $status,
                $jobResult->jobName,
                $jobResult->durationMs(),
            ));
        }

        $output->newLine();

        if ($result->hasFailures()) {
            $output->error(sprintf(
                '%d/%d job(s) failed.',
                $result->jobsFailed,
                $result->jobsRun,
            ));
            return ExitCode::Error->value;
        }

        $output->success(sprintf('Ran %d job(s) successfully.', $result->jobsRun));
        return ExitCode::Success->value;
    }
}
