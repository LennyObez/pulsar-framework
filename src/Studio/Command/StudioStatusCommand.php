<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command;

use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use Pulsar\Api\Internal;
use Pulsar\Config\StudioConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

use function sprintf;

/**
 * Displays the current Studio status and configuration.
 */
#[Internal]
final class StudioStatusCommand extends Command
{
    public function __construct(
        private readonly StudioConfig $config,
        private readonly ?EventStoreInterface $store = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'studio:status';
        $this->description = 'Display Studio status and configuration';
        $this->addOption('json', 'Output as JSON', 'j');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        $status = [
            'enabled' => $this->config->enabled,
            'storage_path' => $this->config->storagePath,
            'sampling_rate' => $this->config->samplingRate,
            'retention' => [
                'max_age_days' => $this->config->retention->maxAgeDays,
                'max_size_mb' => $this->config->retention->maxSizeMb,
            ],
            'server' => [
                'host' => $this->config->server->host,
                'port' => $this->config->server->port,
            ],
            'collectors' => [
                'http' => $this->config->collectors->http,
                'database' => $this->config->collectors->database,
                'logs' => $this->config->collectors->logs,
                'exceptions' => $this->config->collectors->exceptions,
                'scheduler' => $this->config->collectors->scheduler,
                'feature_flags' => $this->config->collectors->featureFlags,
            ],
            'storage' => null,
        ];

        if ($this->store !== null) {
            $status['storage'] = [
                'event_count' => $this->store->count(),
                'size_bytes' => $this->store->sizeInBytes(),
            ];
        }

        if ($isJson) {
            $output->writeln(json_encode($status, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return ExitCode::Success->value;
        }

        $output->writeln('Pulsar Studio Status');
        $output->writeln(str_repeat('=', 30));
        $output->newLine();

        $output->writeln(sprintf('  Enabled:       %s', $this->config->enabled ? 'Yes' : 'No'));
        $output->writeln(sprintf('  Storage:       %s', $this->config->storagePath));
        $output->writeln(sprintf('  Sampling:      %.0f%%', $this->config->samplingRate * 100.0));
        $output->writeln(sprintf('  Retention:     %d days / %d MB', $this->config->retention->maxAgeDays, $this->config->retention->maxSizeMb));
        $output->writeln(sprintf('  Server:        %s:%d', $this->config->server->host, $this->config->server->port));
        $output->newLine();

        $output->info('Collectors');
        $output->writeln(sprintf('  HTTP:          %s', $this->config->collectors->http ? 'Active' : 'Disabled'));
        $output->writeln(sprintf('  Database:      %s', $this->config->collectors->database ? 'Active' : 'Disabled'));
        $output->writeln(sprintf('  Logs:          %s', $this->config->collectors->logs ? 'Active' : 'Disabled'));
        $output->writeln(sprintf('  Exceptions:    %s', $this->config->collectors->exceptions ? 'Active' : 'Disabled'));
        $output->writeln(sprintf('  Scheduler:     %s', $this->config->collectors->scheduler ? 'Active' : 'Disabled'));
        $output->writeln(sprintf('  Feature Flags: %s', $this->config->collectors->featureFlags ? 'Active' : 'Disabled'));

        if ($this->store !== null) {
            $output->newLine();
            $output->info('Storage');
            $output->writeln(sprintf('  Events:        %d', $this->store->count()));
            $output->writeln(sprintf('  Size:          %d bytes', $this->store->sizeInBytes()));
        }

        return ExitCode::Success->value;
    }
}
