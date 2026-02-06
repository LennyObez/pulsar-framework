<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console;

use function count;

use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Studio\Console\Aggregation\TimelineBuilderInterface;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

use function sprintf;

/**
 * Displays a timeline of recent Studio events.
 */
#[Internal]
final class ConsoleTimelineCommand extends Command
{
    public function __construct(
        private readonly EventStoreInterface $store,
        private readonly TimelineBuilderInterface $timeline,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'studio:console:timeline';
        $this->description = 'Display a timeline of recent Studio events';
        $this->addOption('limit', 'Maximum number of events', 'l', '50');
        $this->addOption('json', 'Output as JSON', 'j');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $rawLimit */
        $rawLimit = $input->getOption('limit') ?? '50';
        $limit = (int) $rawLimit;
        $isJson = $input->hasOption('json');

        $events = $this->store->query(limit: $limit);
        $entries = $this->timeline->build($events);

        if ($isJson) {
            $data = array_map(static fn(array $entry): array => $entry, $entries);
            $output->writeln(JsonOutputHelper::encode('studio:console:timeline', true, ['entries' => $data, 'count' => count($entries)]));

            return ExitCode::Success->value;
        }

        $output->writeln('Studio Timeline');
        $output->writeln(str_repeat('=', 50));

        if ($entries === []) {
            $output->writeln('  No events recorded.');

            return ExitCode::Success->value;
        }

        foreach ($entries as $entry) {
            /** @var string $timestamp */
            $timestamp = $entry['timestamp'] ?? '';
            /** @var string $type */
            $type = $entry['type'] ?? '';
            /** @var string $summary */
            $summary = $entry['summary'] ?? '';
            $output->writeln(sprintf('  [%s] %s: %s', $timestamp, $type, $summary));
        }

        $output->writeln('');
        $output->writeln(sprintf('  Total: %d events', count($entries)));

        return ExitCode::Success->value;
    }
}
