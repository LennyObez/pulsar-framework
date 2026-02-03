<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function count;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

use JsonException;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\QueueManager;

use function sprintf;

/**
 * Display the current status of the queue system.
 */
final class QueueStatusCommand extends Command
{
    public function __construct(
        private readonly QueueManager $manager,
        private readonly DeadLetterQueue $deadLetterQueue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'queue:status';
        $this->description = 'Display queue system status';

        $this->addOption('json', 'Output in JSON format');
    }

    /**
     * @throws JsonException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $pendingCount = $this->manager->size();
        $failedCount = count($this->deadLetterQueue->list());

        if ($input->hasOption('json')) {
            $data = [
                'pending' => $pendingCount,
                'failed' => $failedCount,
            ];

            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return ExitCode::Success->value;
        }

        $output->writeln('Queue Status');
        $output->writeln('============');
        $output->newLine();
        $output->writeln(sprintf('  Pending jobs:  %d', $pendingCount));
        $output->writeln(sprintf('  Failed jobs:   %d', $failedCount));
        $output->newLine();

        return ExitCode::Success->value;
    }
}
