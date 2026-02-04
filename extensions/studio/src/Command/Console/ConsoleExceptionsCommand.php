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
 * Displays exception data from Studio events.
 */
#[Internal]
final class ConsoleExceptionsCommand extends Command
{
    public function __construct(
        private readonly DashboardAggregatorInterface $aggregator,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:exceptions';
        $this->description = 'Display exception data from Studio';
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

        /** @var list<array<string, mixed>> $exceptions */
        $exceptions = $metrics['exceptions'] ?? [];

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:exceptions', true, ['exceptions' => $exceptions]));

            return ExitCode::Success->value;
        }

        $output->writeln('Exception Summary');
        $output->writeln(str_repeat('=', 70));
        $output->writeln(sprintf('  %-40s %-10s %-15s', 'Class', 'Count', 'Last Seen'));
        $output->writeln(str_repeat('-', 70));

        if ($exceptions === []) {
            $output->writeln('  No exceptions recorded.');

            return ExitCode::Success->value;
        }

        foreach ($exceptions as $exception) {
            /** @var string $class */
            $class = $exception['class'] ?? 'unknown';
            /** @var int $count */
            $count = $exception['count'] ?? 0;
            /** @var string $lastSeen */
            $lastSeen = $exception['last_seen'] ?? '';

            $output->writeln(sprintf('  %-40s %-10d %-15s', $class, $count, $lastSeen));
        }

        return ExitCode::Success->value;
    }
}
