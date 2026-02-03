<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console\Evidence;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Studio\Command\Console\JsonOutputHelper;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

use function sprintf;

/**
 * Displays the current status of the Studio evidence store.
 */
#[Internal]
final class EvidenceStatusCommand extends Command
{
    public function __construct(
        private readonly EventStoreInterface $store,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:evidence:status';
        $this->description = 'Display evidence store status';
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        $totalEvents = $this->store->count();
        $sizeBytes = $this->store->sizeInBytes();
        $sizeMb = $sizeBytes / (1024 * 1024);

        $data = [
            'total_events' => $totalEvents,
            'size_bytes' => $sizeBytes,
            'size_mb' => round($sizeMb, 2),
        ];

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:evidence:status', true, $data));

            return ExitCode::Success->value;
        }

        $output->writeln('Evidence Store Status');
        $output->writeln(str_repeat('=', 40));
        $output->writeln(sprintf('  Total events:  %d', $totalEvents));
        $output->writeln(sprintf('  Storage size:  %.2f MB (%d bytes)', $sizeMb, $sizeBytes));

        return ExitCode::Success->value;
    }
}
