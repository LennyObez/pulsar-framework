<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command;

use function file_exists;
use function file_get_contents;
use function file_put_contents;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;
use function str_replace;

/**
 * Disables Studio in the configuration file.
 */
#[Internal]
final class StudioDisableCommand extends Command
{
    public function __construct(
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:disable';
        $this->description = 'Disable Studio in configuration';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $configPath = $this->basePath . '/config/studio.php';

        if (!file_exists($configPath)) {
            $output->errorln(sprintf('Config file not found: %s', $configPath));
            return ExitCode::Error->value;
        }

        $contents = file_get_contents($configPath);
        if ($contents === false) {
            $output->errorln('Failed to read config file');
            return ExitCode::Error->value;
        }

        $updated = str_replace("'enabled' => true", "'enabled' => false", $contents);

        if ($updated === $contents) {
            $output->info('Studio is already disabled.');
            return ExitCode::Success->value;
        }

        file_put_contents($configPath, $updated);

        $output->success('Studio has been disabled.');
        $output->writeln('Restart your application for changes to take effect.');

        return ExitCode::Success->value;
    }
}
