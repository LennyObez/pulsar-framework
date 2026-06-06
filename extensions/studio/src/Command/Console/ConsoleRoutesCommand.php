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
 * Displays route performance data from Studio events.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class ConsoleRoutesCommand extends Command
{
    public function __construct(
        private readonly DashboardAggregatorInterface $aggregator,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:routes';
        $this->description = 'Display route performance data from Studio';
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

        /** @var list<array<string, mixed>> $routeMetrics */
        $routeMetrics = $metrics['routes'] ?? [];

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:routes', true, ['routes' => $routeMetrics]));

            return ExitCode::Success->value;
        }

        $output->writeln('Route Performance');
        $output->writeln(str_repeat('=', 70));
        $output->writeln(sprintf('  %-30s %-10s %-12s %-10s', 'Path', 'Hits', 'Avg (ms)', 'Errors'));
        $output->writeln(str_repeat('-', 70));

        if ($routeMetrics === []) {
            $output->writeln('  No route data recorded.');

            return ExitCode::Success->value;
        }

        foreach ($routeMetrics as $route) {
            /** @var string $path */
            $path = $route['path'] ?? '';
            /** @var int $hits */
            $hits = $route['hits'] ?? 0;
            /** @var float $avgMs */
            $avgMs = $route['avg_ms'] ?? 0.0;
            /** @var int $errors */
            $errors = $route['errors'] ?? 0;

            $output->writeln(sprintf('  %-30s %-10d %-12.1f %-10d', $path, $hits, $avgMs, $errors));
        }

        return ExitCode::Success->value;
    }
}
