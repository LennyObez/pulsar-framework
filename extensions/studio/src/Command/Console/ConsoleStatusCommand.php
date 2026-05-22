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

use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Displays Console event store status and statistics.
 */
#[Internal]
final class ConsoleStatusCommand extends Command
{
    public function __construct(
        private readonly EventStoreInterface $store,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:status';
        $this->description = 'Display Console event store status';
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        $status = [
            'event_count' => $this->store->count(),
            'size_bytes' => $this->store->sizeInBytes(),
        ];

        if ($isJson) {
            $output->writeln(json_encode($status, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return ExitCode::Success->value;
        }

        $output->writeln('Console Event Store Status');
        $output->writeln(str_repeat('=', 30));
        $output->newLine();

        $output->writeln(sprintf('  Events:  %d', $status['event_count']));
        $output->writeln(sprintf('  Size:    %d bytes', $status['size_bytes']));

        return ExitCode::Success->value;
    }
}
