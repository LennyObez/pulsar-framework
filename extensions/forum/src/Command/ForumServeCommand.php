<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;

use function file_exists;
use function is_int;
use function is_resource;
use function is_string;
use function proc_close;
use function proc_open;
use function sprintf;

use const PHP_BINARY;
use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * Starts the Forum standalone development server.
 *
 * Bootstraps the full framework kernel so all Forum routes (public pages,
 * account pages, admin, API), database, auth, and extensions are available.
 */
#[Internal]
final class ForumServeCommand extends Command
{
    public function __construct(
        private readonly ForumConfig $config,
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'forum:serve';
        $this->description = 'Start the Forum standalone development server';
        $this->addOption('host', 'Host to bind to', 'H');
        $this->addOption('port', 'Port to listen on', 'p');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $hostOption = $input->getOption('host');
        $host = $input->hasOption('host') && is_string($hostOption)
            ? $hostOption
            : '127.0.0.1';

        $portOption = $input->getOption('port');
        $port = $input->hasOption('port') && (is_int($portOption) || is_string($portOption))
            ? (int) $portOption
            : 8888;

        $routerScript = $this->basePath . '/extensions/forum/dev/router.php';
        if (!file_exists($routerScript)) {
            $output->errorln(sprintf('Router script not found: %s', $routerScript));

            return ExitCode::Error->value;
        }

        $documentRoot = $this->basePath;

        $output->info(sprintf('Starting Forum on http://%s:%d', $host, $port));
        $output->writeln(sprintf('  Guest viewing:  %s', $this->config->allowGuestViewing ? 'enabled' : 'disabled'));
        $output->writeln(sprintf('  Threads/page:   %d', $this->config->threadsPerPage));
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
