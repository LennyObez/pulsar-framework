<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command\Console;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

use function count;
use function explode;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Queries Studio events with filtering and pagination.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class ConsoleQueryCommand extends Command
{
    public function __construct(
        private readonly EventStoreInterface $store,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:query';
        $this->description = 'Query Studio events';
        $this->addOption('type', 'Filter by event type (comma-separated)', 't');
        $this->addOption('request-id', 'Filter by request ID', 'r');
        $this->addOption('job-id', 'Filter by job ID');
        $this->addOption('trace-id', 'Filter by trace ID');
        $this->addOption('limit', 'Maximum number of events', 'l', '50');
        $this->addOption('offset', 'Offset for pagination', null, '0');
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        $filters = [];

        if ($input->hasOption('type')) {
            $type = $input->getStringOption('type');
            $filters['event_type'] = $type !== '' ? explode(',', $type) : [];
        }

        if ($input->hasOption('request-id')) {
            $filters['request_id'] = $input->getStringOption('request-id');
        }

        if ($input->hasOption('job-id')) {
            $filters['job_id'] = $input->getStringOption('job-id');
        }

        if ($input->hasOption('trace-id')) {
            $filters['trace_id'] = $input->getStringOption('trace-id');
        }

        $limit = $input->getIntOption('limit', 50);
        $offset = $input->getIntOption('offset', 0);

        $events = $this->store->query($filters, $limit, $offset);
        $total = $this->store->count($filters);

        if ($isJson) {
            $result = [
                'events' => $events,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ];
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Found %d events (showing %d-%d of %d)', count($events), $offset + 1, $offset + count($events), $total));
        $output->newLine();

        foreach ($events as $event) {
            $extractor = new EventDataExtractor($event);
            $output->writeln(sprintf(
                '  [%s] %-20s  id=%s',
                $extractor->formattedTime(),
                $extractor->eventType(),
                substr($extractor->eventId(), 0, 16),
            ));
        }

        return ExitCode::Success->value;
    }
}
