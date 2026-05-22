<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Queue\QueueDriverInterface;

use function sprintf;

/**
 * Flush (purge) all jobs from a specific queue.
 */
final class QueueFlushCommand extends Command
{
    public function __construct(
        private readonly QueueDriverInterface $driver,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'queue:flush';
        $this->description = 'Purge all jobs from a queue';

        $this->addArgument('queue', 'The queue name to flush');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $queue */
        $queue = $input->getArgument(0, 'default');

        $purged = $this->driver->purge($queue);

        if ($purged === 0) {
            $output->info(sprintf('Queue "%s" is already empty.', $queue));
            return ExitCode::Success->value;
        }

        $output->success(sprintf('Purged %d job(s) from queue "%s".', $purged, $queue));

        return ExitCode::Success->value;
    }
}
