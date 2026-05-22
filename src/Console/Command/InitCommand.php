<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use JsonException;
use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\NewProject\EnvironmentPreset;
use Pulsar\Console\Command\NewProject\ProjectGenerator;
use Pulsar\Console\Command\NewProject\ProjectPreset;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Random\RandomException;
use RuntimeException;

use function basename;
use function is_string;
use function sprintf;

/**
 * Initialize a new Pulsar project in the current (or specified) directory.
 *
 * Delegates to {@see ProjectGenerator} with the Minimal preset to ensure
 * output is consistent with `pulsar new --preset=minimal`.
 */
final class InitCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->name = 'init';
        $this->description = 'Initialize a new Pulsar project';
        $this->addArgument('directory', 'Target directory (default: current directory)');
    }

    /**
     * @throws JsonException If composer.json encoding fails
     * @throws RandomException If cryptographic random generation fails
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $input->getArgument(0) ?? getcwd();

        if (!is_string($directory)) {
            $output->errorln('Invalid directory argument.');
            return ExitCode::Invalid->value;
        }

        // Resolve to absolute path
        if (!str_starts_with($directory, '/') && !preg_match('/^[A-Za-z]:/', $directory)) {
            $cwd = getcwd();
            if ($cwd === false) {
                $output->errorln('Failed to get current working directory.');
                return ExitCode::Error->value;
            }
            $directory = $cwd . DIRECTORY_SEPARATOR . $directory;
        }

        $name = basename($directory);

        if ($name === '' || $name === '.') {
            $name = 'my-app';
        }

        try {
            $generator = new ProjectGenerator();
            $generator->generate($name, ProjectPreset::Minimal, EnvironmentPreset::Local, $directory, $output);
        } catch (RuntimeException $e) {
            $output->newLine();
            $output->errorln($e->getMessage());
            return ExitCode::Error->value;
        }

        $output->newLine();
        $output->success('Project initialized successfully!');
        $output->newLine();
        $output->writeln('Next steps:');
        $output->writeln(sprintf('  cd %s', basename($directory)));
        $output->writeln('  composer install');
        $output->writeln('  php -S localhost:8000 -t public');

        return ExitCode::Success->value;
    }
}
