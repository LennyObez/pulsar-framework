<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console\Guardian;

use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Studio\Command\Console\JsonOutputHelper;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

use function sprintf;

/**
 * Displays a combined guardian status overview covering
 * integrity, supervisor, and evidence subsystems.
 */
#[Internal]
final class GuardianStatusCommand extends Command
{
    public function __construct(
        private readonly EventStoreInterface $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'studio:console:guardian:status';
        $this->description = 'Display combined guardian status overview';
        $this->addOption('json', 'Output as JSON', 'j');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        $totalEvents = $this->store->count();
        $sizeBytes = $this->store->sizeInBytes();

        $data = [
            'evidence' => [
                'total_events' => $totalEvents,
                'size_bytes' => $sizeBytes,
                'size_mb' => round($sizeBytes / (1024 * 1024), 2),
            ],
        ];

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:guardian:status', true, $data));

            return ExitCode::Success->value;
        }

        $output->writeln('Guardian Status');
        $output->writeln(str_repeat('=', 50));
        $output->writeln('');
        $output->writeln('  Evidence Store:');
        $output->writeln(sprintf('    Events:  %d', $totalEvents));
        $output->writeln(sprintf('    Size:    %.2f MB', $sizeBytes / (1024 * 1024)));

        return ExitCode::Success->value;
    }
}
