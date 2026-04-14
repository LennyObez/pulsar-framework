<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;

use function file_exists;
use function is_resource;
use function proc_close;
use function proc_open;
use function sprintf;

use const PHP_BINARY;
use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * Starts the Admin panel development server.
 *
 * Bootstraps the full framework kernel so Admin routes, database,
 * auth, and all extensions are available.
 */
#[Internal]
final class AdminServeCommand extends Command
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
        $this->name = 'admin:serve';
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

        $host = $input->getStringOption('host', '127.0.0.1');
        $port = $input->getIntOption('port', 8686);

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

        $address = sprintf('%s:%d', $host, $port);

        // nosemgrep: php.lang.security.exec-use.exec-use: array form bypasses the shell entirely
        $process = proc_open(
            [PHP_BINARY, '-S', $address, '-t', $documentRoot, $routerScript],
            [STDIN, STDOUT, STDERR],
            $pipes,
        );

        if (!is_resource($process)) {
            $output->errorln('Failed to start PHP built-in server');

            return ExitCode::Error->value;
        }

        $exitCode = proc_close($process);

        return $exitCode === 0 ? ExitCode::Success->value : ExitCode::Error->value;
    }
}
