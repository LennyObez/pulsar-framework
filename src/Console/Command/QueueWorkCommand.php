<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Queue\WorkerFactoryInterface;
use Pulsar\Queue\WorkerOptions;

use function sprintf;

/**
 * Start a queue worker to process jobs from a given queue.
 *
 * Takes the factory rather than the driver. Building a Worker here from a driver
 * and a logger left eight collaborators unset, so a worker started from the
 * command line ran with no dead-letter queue, no retry policy, no metrics, no
 * events and no execution pipeline — the last of which carries payload
 * decryption. See {@see WorkerFactoryInterface}.
 */
final class QueueWorkCommand extends Command
{
    public function __construct(
        private readonly WorkerFactoryInterface $workers,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'queue:work';
        $this->description = 'Start processing jobs from a queue';

        $this->addArgument('queue', 'The queue name to process');
        $this->addOption('max-jobs', 'Maximum number of jobs to process', null, '1000');
        $this->addOption('memory', 'Memory limit in megabytes', null, '128');
        $this->addOption('timeout', 'Time limit in seconds', null, '3600');
        $this->addOption('sleep', 'Sleep time in milliseconds when queue is empty', null, '1000');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $queue */
        $queue = $input->getArgument(0, 'default');

        /** @var string $rawMaxJobs */
        $rawMaxJobs = $input->getOption('max-jobs', '1000');
        /** @var string $rawMemory */
        $rawMemory = $input->getOption('memory', '128');
        /** @var string $rawTimeout */
        $rawTimeout = $input->getOption('timeout', '3600');
        /** @var string $rawSleep */
        $rawSleep = $input->getOption('sleep', '1000');

        $options = new WorkerOptions(
            maxJobs: (int) $rawMaxJobs,
            maxMemoryMb: (int) $rawMemory,
            timeLimitSeconds: (int) $rawTimeout,
            sleepMs: (int) $rawSleep,
        );

        $output->info(sprintf(
            'Starting worker on queue "%s" (max-jobs: %d, memory: %dMB, timeout: %ds)',
            $queue,
            $options->maxJobs,
            $options->maxMemoryMb,
            $options->timeLimitSeconds,
        ));

        $this->workers->create($options)->run($queue);

        $output->success('Worker stopped gracefully.');

        return ExitCode::Success->value;
    }
}
