<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command\Console;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

use function explode;
use function function_exists;
use function json_encode;
use function pcntl_async_signals;
use function pcntl_signal;
use function sprintf;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

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

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $typeOption = $input->hasOption('type') ? $input->getStringOption('type') : '';
        $typeFilter = $typeOption !== '' ? explode(',', $typeOption) : [];

        $correlationFilter = $input->hasOption('filter')
            ? $input->getNullableStringOption('filter')
            : null;

        $isJson = $input->hasOption('json');
        $lines = $input->getIntOption('lines', 20);

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

        // A tail is long-running, not unstoppable. The loop used to be an
        // unconditional for(;;) carrying a @psalm-suppress InvalidReturnType, which
        // is an accurate description of a method that can only be ended by killing
        // the process: no exit code reaches the shell, no `finally` runs, and any
        // test of it hangs the suite. Ctrl+C now unwinds it normally where the
        // platform can tell us about signals.
        $stop = false;

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $interrupt = static function () use (&$stop): void {
                $stop = true;
            };

            // Only evaluated behind the function_exists() guard above, so the
            // constants are never touched on a build without ext-pcntl.
            pcntl_signal(SIGINT, $interrupt);
            pcntl_signal(SIGTERM, $interrupt);
        }

        while (!$stop) {
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

        if (!$isJson) {
            $output->writeln('--- Stopped ---');
        }

        return 0;
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
