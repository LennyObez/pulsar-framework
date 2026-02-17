<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use DateInvalidTimeZoneException;
use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Scheduler\Exception\SchedulerException;
use Pulsar\Scheduler\Scheduler;

use function sprintf;

/**
 * Run all due scheduled tasks.
 *
 * This command evaluates every registered scheduled job and executes
 * those whose cron expression matches the current time. Designed
 * to be called once per minute from the system crontab:
 *
 *   * * * * * cd /path/to/project && php pulsar schedule:run >> /dev/null 2>&1
 */
final class ScheduleRunCommand extends Command
{
    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'schedule:run';
        $this->description = 'Run all due scheduled tasks';
    }

    /**
     * @throws DateInvalidTimeZoneException If a job's schedule has an invalid timezone.
     * @throws SchedulerException If a job's cron expression is invalid.
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->scheduler->tick();

        if ($result->jobsDue === 0) {
            $output->info('No scheduled tasks are due.');

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
                '%d/%d task(s) failed.',
                $result->jobsFailed,
                $result->jobsRun,
            ));

            return ExitCode::Error->value;
        }

        $output->success(sprintf('Ran %d task(s) successfully.', $result->jobsRun));

        return ExitCode::Success->value;
    }
}
