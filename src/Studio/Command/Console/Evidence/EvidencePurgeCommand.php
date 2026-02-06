<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console\Evidence;

use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Studio\Command\Console\JsonOutputHelper;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

/**
 * Purges all events from the Studio evidence store.
 */
#[Internal]
final class EvidencePurgeCommand extends Command
{
    public function __construct(
        private readonly EventStoreInterface $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'studio:console:evidence:purge';
        $this->description = 'Purge all events from evidence store';
        $this->addOption('confirm', 'Confirm the purge operation', 'c');
        $this->addOption('json', 'Output as JSON', 'j');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        if (!$input->hasOption('confirm')) {
            $message = 'This will permanently delete all Studio events. Pass --confirm to proceed.';

            if ($isJson) {
                $output->writeln(JsonOutputHelper::encode('studio:console:evidence:purge', false, ['error' => $message]));
            } else {
                $output->writeln($message);
            }

            return ExitCode::Error->value;
        }

        $countBefore = $this->store->count();
        $this->store->clear();
        $this->store->vacuum();

        $data = [
            'events_purged' => $countBefore,
        ];

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:evidence:purge', true, $data));

            return ExitCode::Success->value;
        }

        $output->writeln('Evidence Store Purged');
        $output->writeln("  Removed {$countBefore} events.");

        return ExitCode::Success->value;
    }
}
