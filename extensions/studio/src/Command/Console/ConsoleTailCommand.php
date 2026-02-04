<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command\Console;

use function explode;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

use function sprintf;
use function usleep;

/**
 * Streams Studio events in real-time from the command line.
 */
#[Internal]
final class ConsoleTailCommand extends Command
{
    public function __construct(
        private readonly EventStoreInterface $store,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:tail';
        $this->description = 'Stream Studio events in real-time';
        $this->addOption('type', 'Filter by event type (comma-separated)', 't');
        $this->addOption('filter', 'Filter by correlation ID', 'f');
        $this->addOption('json', 'Output as JSON lines', 'j');
        $this->addOption('lines', 'Number of past events to show', 'n', '20');
    }

    /** @psalm-suppress InvalidReturnType Infinite poll loop — exits only via SIGINT */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawTypeOption = $input->hasOption('type') ? $input->getOption('type') : null;
        $typeFilter = is_string($rawTypeOption) ? explode(',', $rawTypeOption) : [];

        $rawFilterOption = $input->hasOption('filter') ? $input->getOption('filter') : null;
        $correlationFilter = is_string($rawFilterOption) ? $rawFilterOption : null;

        $isJson = $input->hasOption('json');
        $rawLines = $input->getOption('lines', '20') ?? '20';
        $lines = is_numeric($rawLines) ? (int) $rawLines : 20;

        // Build initial query filters
        $filters = [];
        if ($typeFilter !== []) {
            $filters['event_type'] = $typeFilter;
        }
        if ($correlationFilter !== null) {
            $filters['request_id'] = $correlationFilter;
        }

        // Show recent events first
        $recent = $this->store->query($filters, limit: $lines);

        $lastId = 0;
        foreach ($recent as $event) {
            $this->outputEvent($event, $isJson, $output);
            $extractor = new EventDataExtractor($event);
            $lastId = $extractor->id();
        }

        if (!$isJson) {
            $output->writeln('--- Watching for new events (Ctrl+C to stop) ---');
        }

        // Poll for new events (intentional infinite loop — exits via Ctrl+C / signal)
        for (;;) {
            $pollFilters = $filters;
            if ($lastId > 0) {
                $pollFilters['since_id'] = $lastId;
            }

            $events = $this->store->query($pollFilters, limit: 100);

            foreach ($events as $event) {
                $this->outputEvent($event, $isJson, $output);
                $extractor = new EventDataExtractor($event);
                $eventId = $extractor->id();
                if ($eventId > $lastId) {
                    $lastId = $eventId;
                }
            }

            usleep(500_000); // 500ms polling interval
        }
    }

    /**
     * @param array<string, mixed> $event
     * @throws JsonException If JSON encoding fails
     */
    private function outputEvent(array $event, bool $isJson, OutputInterface $output): void
    {
        if ($isJson) {
            $output->writeln(json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return;
        }

        $extractor = new EventDataExtractor($event);
        $requestId = $extractor->requestId();

        $line = sprintf(
            '[%s] %-20s %s',
            $extractor->formattedTime('H:i:s'),
            $extractor->eventType(),
            $requestId !== '' ? sprintf('req=%s', substr($requestId, 0, 12)) : '',
        );

        $output->writeln($line);
    }
}
