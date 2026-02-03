<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\Exception\QueueException;

use function sprintf;

/**
 * Retry a single failed job or all failed jobs from the dead-letter queue.
 */
final class QueueRetryCommand extends Command
{
    public function __construct(
        private readonly DeadLetterQueue $deadLetterQueue,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'queue:retry';
        $this->description = 'Retry a failed job or all failed jobs';

        $this->addArgument('id', 'The failed job ID, or "all" to retry everything', true);
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $id */
        $id = $input->getArgument(0);

        if ($id === '') {
            $output->error('A job ID or "all" is required.');
            return ExitCode::Error->value;
        }

        if ($id === 'all') {
            return $this->retryAll($output);
        }

        return $this->retrySingle($id, $output);
    }

    private function retryAll(OutputInterface $output): int
    {
        $count = $this->deadLetterQueue->retryAll();

        if ($count === 0) {
            $output->info('No failed jobs to retry.');
            return ExitCode::Success->value;
        }

        $output->success(sprintf('Retried %d failed job(s).', $count));

        return ExitCode::Success->value;
    }

    private function retrySingle(string $id, OutputInterface $output): int
    {
        try {
            $this->deadLetterQueue->retry($id);
        } catch (QueueException $e) {
            $output->error($e->getMessage());
            return ExitCode::Error->value;
        }

        $output->success(sprintf('Failed job "%s" has been re-dispatched.', $id));

        return ExitCode::Success->value;
    }
}
