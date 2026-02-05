<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command;

use const PHP_OS_FAMILY;

use Pulsar\Api\Internal;
use Pulsar\Config\StudioConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Opens the Studio dashboard in the default browser.
 */
#[Internal]
final class StudioOpenCommand extends Command
{
    public function __construct(
        private readonly StudioConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'studio:open';
        $this->description = 'Open Studio in the default browser';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = sprintf('http://%s:%d/studio', $this->config->server->host, $this->config->server->port);

        $output->info(sprintf('Opening %s', $url));

        $command = match (PHP_OS_FAMILY) {
            'Windows' => sprintf('start %s', escapeshellarg($url)),
            'Darwin' => sprintf('open %s', escapeshellarg($url)),
            default => sprintf('xdg-open %s', escapeshellarg($url)),
        };

        exec($command, result_code: $exitCode);

        if ($exitCode !== 0) {
            $output->warning(sprintf('Could not open browser. Visit: %s', $url));
        }

        return ExitCode::Success->value;
    }
}
