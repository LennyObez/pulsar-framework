<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Command;

use function is_int;
use function is_string;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;

use function sprintf;

/**
 * Starts the Admin panel development server.
 *
 * Bootstraps the full framework kernel so Admin routes, database,
 * auth, and all extensions are available.
 */
#[Internal]
final class AdminStartCommand extends Command
{
    public function __construct(
        private readonly AdminConfig $config,
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'admin:start';
        $this->description = 'Start the Admin panel development server';
        $this->addOption('host', 'Host to bind to', 'H');
        $this->addOption('port', 'Port to listen on', 'p');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->enabled) {
            $output->errorln('Admin panel is not enabled. Set enabled: true in config/admin.php');

            return ExitCode::Error->value;
        }

        $hostOption = $input->getOption('host');
        $host = $input->hasOption('host') && is_string($hostOption)
            ? $hostOption
            : '127.0.0.1';

        $portOption = $input->getOption('port');
        $port = $input->hasOption('port') && (is_int($portOption) || is_string($portOption))
            ? (int) $portOption
            : 8686;

        $routerScript = $this->basePath . '/extensions/admin/dev/router.php';
        if (!file_exists($routerScript)) {
            $output->errorln(sprintf('Router script not found: %s', $routerScript));

            return ExitCode::Error->value;
        }

        $documentRoot = $this->basePath;

        $output->info(sprintf('Starting Admin panel on http://%s:%d%s', $host, $port, $this->config->routePrefix));
        $output->writeln(sprintf('  Route prefix:  %s', $this->config->routePrefix));
        $output->newLine();
        $output->writeln('Press Ctrl+C to stop.');
        $output->newLine();

        $command = sprintf(
            'php -S %s:%d -t %s %s',
            $host,
            $port,
            escapeshellarg($documentRoot),
            escapeshellarg($routerScript),
        );

        passthru($command, $exitCode);

        return $exitCode === 0 ? ExitCode::Success->value : ExitCode::Error->value;
    }
}
