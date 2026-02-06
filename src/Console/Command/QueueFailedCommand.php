<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function count;
use function date;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

use JsonException;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\FailedJob;

use function sprintf;
use function str_pad;
use function strlen;
use function substr;

/**
 * List all failed jobs in the dead-letter queue.
 */
final class QueueFailedCommand extends Command
{
    public function __construct(
        private readonly DeadLetterQueue $deadLetterQueue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'queue:failed';
        $this->description = 'List all failed jobs';

        $this->addOption('json', 'Output in JSON format');
    }

    /**
     * @throws JsonException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $failedJobs = $this->deadLetterQueue->list();

        if ($failedJobs === []) {
            $output->info('No failed jobs.');
            return ExitCode::Success->value;
        }

        if ($input->hasOption('json')) {
            return $this->outputJson($failedJobs, $output);
        }

        return $this->outputTable($failedJobs, $output);
    }

    /**
     * @param list<FailedJob> $failedJobs
     *
     * @throws JsonException
     */
    private function outputJson(array $failedJobs, OutputInterface $output): int
    {
        $data = [];

        foreach ($failedJobs as $job) {
            $data[] = [
                'id' => $job->id,
                'queue' => $job->queue,
                'job_class' => $job->jobClass,
                'exception' => $job->exception,
                'failed_at' => date('Y-m-d H:i:s', $job->failedAt),
                'attempts' => $job->attempts,
            ];
        }

        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ExitCode::Success->value;
    }

    /**
     * @param list<FailedJob> $failedJobs
     */
    private function outputTable(array $failedJobs, OutputInterface $output): int
    {
        $output->writeln(sprintf('Failed Jobs (%d)', count($failedJobs)));
        $output->writeln(str_pad('', 60, '='));
        $output->newLine();

        foreach ($failedJobs as $job) {
            $output->writeln(sprintf('  ID:        %s', $job->id));
            $output->writeln(sprintf('  Queue:     %s', $job->queue));
            $output->writeln(sprintf('  Job:       %s', $job->jobClass));
            $output->writeln(sprintf('  Attempts:  %d', $job->attempts));
            $output->writeln(sprintf('  Failed At: %s', date('Y-m-d H:i:s', $job->failedAt)));
            $output->writeln(sprintf('  Error:     %s', $this->truncate($job->exception)));
            $output->newLine();
        }

        return ExitCode::Success->value;
    }

    /**
     * Truncate a string to a maximum length, appending "..." if needed.
     */
    private function truncate(string $text, int $maxLength = 120): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength - 3) . '...';
    }
}
