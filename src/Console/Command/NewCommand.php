<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function basename;
use function is_string;

use JsonException;
use Pulsar\Console\Command;
use Pulsar\Console\Command\NewProject\EnvironmentPreset;
use Pulsar\Console\Command\NewProject\ProjectGenerator;
use Pulsar\Console\Command\NewProject\ProjectPreset;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use RuntimeException;

use function sprintf;

/**
 * Create a new Pulsar project.
 *
 * Usage:
 *   pulsar new my-app
 *   pulsar new my-app --preset=api
 *   pulsar new my-app --preset=minimal --env=production
 */
final class NewCommand extends Command
{
    use ScaffoldTrait;

    protected function configure(): void
    {
        $this->name = 'new';
        $this->description = 'Create a new Pulsar project';
        $this->addArgument('name', 'Project name (used as directory name)', true);
        $this->addOption('preset', 'Project preset: minimal, web, api', 'p', 'web');
        $this->addOption('env', 'Environment preset: local, staging, production', 'e', 'local');
    }

    /**
     * @throws JsonException If composer.json encoding fails (propagated from generator)
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);

        if (!is_string($name) || $name === '') {
            $output->errorln('Project name is required.');
            $output->newLine();
            $output->writeln(sprintf('Usage: %s', $this->getUsage()));
            return ExitCode::Invalid->value;
        }

        // Resolve presets from option values
        $presetValue = $input->getOption('preset', 'web');
        $envValue = $input->getOption('env', 'local');

        $preset = ProjectPreset::fromInput(is_string($presetValue) ? $presetValue : null);
        $env = EnvironmentPreset::fromInput(is_string($envValue) ? $envValue : null);

        // Resolve target directory relative to cwd
        $cwd = getcwd();

        if ($cwd === false) {
            $output->errorln('Failed to get current working directory.');
            return ExitCode::Error->value;
        }

        $targetPath = $cwd . DIRECTORY_SEPARATOR . $name;

        $output->newLine();

        try {
            $generator = new ProjectGenerator();
            $generator->generate($name, $preset, $env, $targetPath, $output);
        } catch (RuntimeException $e) {
            $output->newLine();
            $output->errorln($e->getMessage());
            return ExitCode::Error->value;
        }

        $output->newLine();
        $output->success(sprintf('Project "%s" created successfully!', $name));
        $output->newLine();
        $output->writeln('Next steps:');
        $output->writeln(sprintf('  cd %s', basename($targetPath)));
        $output->writeln('  composer install');

        $output->writeln('  php -S localhost:8000 -t public');

        if ($preset === ProjectPreset::Api) {
            $output->writeln('  curl http://localhost:8000/health');
        } else {
            $output->writeln('  Open http://localhost:8000 in your browser');
        }

        return ExitCode::Success->value;
    }
}
