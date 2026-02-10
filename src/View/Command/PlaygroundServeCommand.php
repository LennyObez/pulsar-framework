<?php

declare(strict_types=1);

namespace Pulsar\View\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function escapeshellarg;
use function getenv;
use function is_dir;
use function is_file;
use function passthru;
use function sprintf;
use function strtolower;

/**
 * Starts the UI playground development server.
 *
 * Serves a component catalog with a live CSS editor for real-time
 * theme customization. Dev-only — disabled in production environments.
 */
#[Internal(reason: 'CLI command implementation')]
final class PlaygroundServeCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'playground:serve';
        $this->description = 'Start the Playground development server';
        $this->addOption('port', 'Port to serve on', '-p', '8942');
        $this->addOption('host', 'Host to bind to', '-H', '127.0.0.1');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->isProduction()) {
            $output->error('The UI playground is not available in production environments.');

            return ExitCode::Error->value;
        }

        /** @var string $port */
        $port = $input->getOption('port', '8942');
        /** @var string $host */
        $host = $input->getOption('host', '127.0.0.1');

        $docRoot = $this->projectRoot . '/resources/playground';

        if (!is_dir($docRoot)) {
            $output->error(sprintf('Playground directory not found: %s', $docRoot));

            return ExitCode::Error->value;
        }

        $routerPath = $docRoot . '/router.php';
        $address = sprintf('%s:%s', $host, $port);

        $output->info(sprintf('Starting UI playground at http://%s', $address));
        $output->info('Press Ctrl+C to stop.');

        $command = sprintf(
            'php -S %s -t %s %s',
            escapeshellarg($address),
            escapeshellarg($docRoot),
            is_file($routerPath) ? escapeshellarg($routerPath) : '',
        );

        passthru($command, $exitCode);

        return $exitCode === 0 ? ExitCode::Success->value : ExitCode::Error->value;
    }

    private function isProduction(): bool
    {
        $env = getenv('APP_ENV');

        if ($env === false) {
            return false;
        }

        return strtolower($env) === 'production';
    }
}
