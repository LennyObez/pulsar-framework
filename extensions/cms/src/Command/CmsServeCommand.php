<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function escapeshellarg;
use function file_exists;
use function is_int;
use function is_string;
use function passthru;
use function sprintf;

/**
 * Starts the CMS development server.
 *
 * Bootstraps the full framework kernel so CMS routes (public + admin),
 * database, auth, and all extensions are available.
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
        $hostOption = $input->getOption('host');
        $host = $input->hasOption('host') && is_string($hostOption)
            ? $hostOption
            : '127.0.0.1';

        $portOption = $input->getOption('port');
        $port = $input->hasOption('port') && (is_int($portOption) || is_string($portOption))
            ? (int) $portOption
            : 8787;

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
