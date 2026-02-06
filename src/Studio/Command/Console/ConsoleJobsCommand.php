<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console;

use function count;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

use function sprintf;

/**
 * Displays job processing data from Studio events.
 */
#[Internal]
final class ConsoleJobsCommand extends Command
{
    public function __construct(
        private readonly EventStoreInterface $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'studio:console:jobs';
        $this->description = 'Display job processing data from Studio';
        $this->addOption('limit', 'Maximum number of events', 'l', '50');
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $rawLimit */
        $rawLimit = $input->getOption('limit') ?? '50';
        $limit = (int) $rawLimit;
        $isJson = $input->hasOption('json');

        $jobTypes = [
            EventType::JobQueued->value,
            EventType::JobProcessing->value,
            EventType::JobCompleted->value,
            EventType::JobFailed->value,
        ];

        $events = $this->store->query(filters: ['types' => $jobTypes], limit: $limit);

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:jobs', true, [
                'events' => $events,
                'count' => count($events),
            ]));

            return ExitCode::Success->value;
        }

        $output->writeln('Job Activity');
        $output->writeln(str_repeat('=', 60));

        if ($events === []) {
            $output->writeln('  No job events recorded.');

            return ExitCode::Success->value;
        }

        foreach ($events as $event) {
            /** @var string $timestamp */
            $timestamp = $event['timestamp'] ?? '';
            /** @var string $type */
            $type = $event['type'] ?? '';
            /** @var array<string, mixed> $payload */
            $payload = $event['payload'] ?? [];
            /** @var string $jobClass */
            $jobClass = $payload['job_class'] ?? 'unknown';

            $output->writeln(sprintf('  [%s] %s: %s', $timestamp, $type, $jobClass));
        }

        $output->writeln();
        $output->writeln(sprintf('  Total: %d events', count($events)));

        return ExitCode::Success->value;
    }
}
