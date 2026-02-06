<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command;

use function file_exists;
use function is_dir;
use function is_int;
use function is_string;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\StudioConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Starts the Studio development server.
 */
#[Internal]
final class StudioStartCommand extends Command
{
    public function __construct(
        private readonly StudioConfig $config,
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:start';
        $this->description = 'Start the Studio development server';
        $this->addOption('host', 'Host to bind to', 'H');
        $this->addOption('port', 'Port to listen on', 'p');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->enabled) {
            $output->errorln('Studio is not enabled. Set enabled: true in config/studio.php');
            return ExitCode::Error->value;
        }

        $hostOption = $input->getOption('host');
        $host = $input->hasOption('host') && is_string($hostOption)
            ? $hostOption
            : $this->config->server->host;

        $portOption = $input->getOption('port');
        $port = $input->hasOption('port') && (is_int($portOption) || is_string($portOption))
            ? (int) $portOption
            : $this->config->server->port;

        $documentRoot = $this->basePath . '/' . $this->config->server->documentRoot;

        if (!is_dir($documentRoot)) {
            $output->errorln(sprintf('Document root does not exist: %s', $documentRoot));
            return ExitCode::Error->value;
        }

        $routerScript = $this->basePath . '/resources/studio/router.php';
        if (!file_exists($routerScript)) {
            $routerScript = '';
        }

        $output->info(sprintf('Starting Studio server on http://%s:%d', $host, $port));
        $output->writeln(sprintf('  Document root: %s', $documentRoot));
        $output->writeln(sprintf('  Storage:       %s', $this->config->storagePath));
        $output->newLine();
        $output->writeln('Press Ctrl+C to stop.');
        $output->newLine();

        $command = sprintf(
            'php -S %s:%d -t %s %s',
            $host,
            $port,
            escapeshellarg($documentRoot),
            $routerScript !== '' ? escapeshellarg($routerScript) : '',
        );

        passthru($command, $exitCode);

        return $exitCode === 0 ? ExitCode::Success->value : ExitCode::Error->value;
    }
}
