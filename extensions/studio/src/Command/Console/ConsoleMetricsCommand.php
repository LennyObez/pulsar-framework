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
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregatorInterface;

use function sprintf;

/**
 * Displays aggregated metrics from Studio events.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class ConsoleMetricsCommand extends Command
{
    public function __construct(
        private readonly DashboardAggregatorInterface $aggregator,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:metrics';
        $this->description = 'Display aggregated Studio metrics';
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        $metrics = $this->aggregator->aggregate();

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:metrics', true, $metrics));

            return ExitCode::Success->value;
        }

        $output->writeln('Studio Metrics');
        $output->writeln(str_repeat('=', 50));

        /** @var int $totalEvents */
        $totalEvents = $metrics['total_events'] ?? 0;
        /** @var int $totalRequests */
        $totalRequests = $metrics['total_requests'] ?? 0;
        /** @var float $avgResponseMs */
        $avgResponseMs = $metrics['avg_response_ms'] ?? 0.0;
        /** @var int $totalExceptions */
        $totalExceptions = $metrics['total_exceptions'] ?? 0;
        /** @var int $totalQueries */
        $totalQueries = $metrics['total_queries'] ?? 0;

        $output->writeln(sprintf('  Total events:     %d', $totalEvents));
        $output->writeln(sprintf('  HTTP requests:    %d', $totalRequests));
        $output->writeln(sprintf('  Avg response:     %.1f ms', $avgResponseMs));
        $output->writeln(sprintf('  Exceptions:       %d', $totalExceptions));
        $output->writeln(sprintf('  DB queries:       %d', $totalQueries));

        return ExitCode::Success->value;
    }
}
