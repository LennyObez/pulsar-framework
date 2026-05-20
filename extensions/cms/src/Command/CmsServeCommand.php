<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function file_exists;
use function is_resource;
use function preg_match;
use function proc_close;
use function proc_open;
use function sprintf;

use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * Starts the CMS development server.
 *
 * Bootstraps the full framework kernel so CMS routes (public + admin),
 * database, auth, and all extensions are available.
 *
 * @psalm-api Registered with the console kernel by name from the CMS service
 *            provider; instantiated by the Command bus, not by name.
 */
#[Internal]
final class CmsServeCommand extends Command
{
    public function __construct(
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'cms:serve';
        $this->description = 'Start the CMS development server';
        $this->addOption('host', 'Host to bind to', 'H');
        $this->addOption('port', 'Port to listen on', 'p');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $input->getStringOption('host', '127.0.0.1');

        if (preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
            $output->errorln(sprintf('Invalid --host value: %s', $host));

            return ExitCode::Error->value;
        }

        $port = $input->getIntOption('port', 8787);

        if ($port < 1 || $port > 65535) {
            $output->errorln(sprintf('Invalid --port value: %d', $port));

            return ExitCode::Error->value;
        }

        $routerScript = $this->basePath . '/extensions/cms/dev/router.php';
        if (!file_exists($routerScript)) {
            $output->errorln(sprintf('Router script not found: %s', $routerScript));

            return ExitCode::Error->value;
        }

        $documentRoot = $this->basePath;

        $output->info(sprintf('Starting CMS server on http://%s:%d', $host, $port));
        $output->writeln(sprintf('  Document root: %s', $documentRoot));
        $output->newLine();
        $output->writeln('Press Ctrl+C to stop.');
        $output->newLine();

        // proc_open() receives an argv array (PHP 7.4+), so the shell is never
        // involved -- no metacharacter expansion, no command-injection surface.
        // $host is whitelist-validated and $port is range-validated above.
        // nosemgrep: php.lang.security.exec-use.exec-use
        $process = proc_open(
            ['php', '-S', $host . ':' . $port, '-t', $documentRoot, $routerScript],
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
        );

        if (!is_resource($process)) {
            $output->errorln('Failed to start the development server process.');

            return ExitCode::Error->value;
        }

        $exitCode = proc_close($process);

        return $exitCode === 0 ? ExitCode::Success->value : ExitCode::Error->value;
    }
}
