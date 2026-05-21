<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use JsonException;
use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\NewProject\EnvironmentPreset;
use Pulsar\Console\Command\NewProject\PackInstaller;
use Pulsar\Console\Command\NewProject\PackLoader;
use Pulsar\Console\Command\NewProject\ProjectGenerator;
use Pulsar\Console\Command\NewProject\ProjectPreset;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Random\RandomException;
use RuntimeException;

use function basename;
use function implode;
use function is_string;
use function sprintf;

/**
 * Create a new Pulsar project.
 *
 * Usage:
 *   pulsar new my-app
 *   pulsar new my-app --preset=api
 *   pulsar new my-app --preset=minimal --env=production
 *   pulsar new my-app --pack=banking
 *   pulsar new --list-packs
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class NewCommand extends Command
{
    use ScaffoldTrait;

    #[Override]
    protected function configure(): void
    {
        $this->name = 'new';
        $this->description = 'Create a new Pulsar project';
        $this->addArgument('name', 'Project name (used as directory name)', true);
        $this->addOption('preset', 'Project preset: minimal, web, api', 'p', 'web');
        $this->addOption('env', 'Environment preset: local, staging, production', 'e', 'local');
        $this->addOption('pack', 'Control pack to install (e.g., banking, healthcare)');
        $this->addOption('list-packs', 'List available scaffolding packs', 'l');
    }

    /**
     * @throws JsonException If composer.json encoding fails (propagated from generator)
     * @throws RandomException If cryptographic random generation fails
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Handle --list-packs
        if ($input->getOption('list-packs') !== null) {
            return $this->listPacks($output);
        }

        $name = $input->getArgument(0);

        if (!is_string($name) || $name === '') {
            $output->errorln('Project name is required.');
            $output->newLine();
            $output->writeln(sprintf('Usage: %s', $this->getUsage()));
            return ExitCode::Invalid->value;
        }

        // Resolve presets from option values
        $packValue = $input->getNullableStringOption('pack');

        $preset = ProjectPreset::fromInput($input->getStringOption('preset', 'web'));
        $env = EnvironmentPreset::fromInput($input->getStringOption('env', 'local'));

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

            // Install scaffolding pack if specified
            if ($packValue !== null && $packValue !== '') {
                $output->newLine();
                $installer = new PackInstaller();
                $installer->install($packValue, $name, $targetPath, $output);
            }
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

        if (is_string($packValue) && $packValue !== '') {
            $output->newLine();
            $output->writeln('  Review SCAFFOLDING.md for scaffolding coverage.');
            $output->writeln('  Review NOT-CERTIFIED.md for important disclaimers.');
        }

        return ExitCode::Success->value;
    }

    /**
     * List all available scaffolding packs.
     */
    private function listPacks(OutputInterface $output): int
    {
        $loader = new PackLoader();
        $packs = $loader->available();

        if ($packs === []) {
            $output->writeln('No scaffolding packs available.');
            return ExitCode::Success->value;
        }

        $output->newLine();
        $output->writeln('Available scaffolding packs:');
        $output->newLine();

        foreach ($packs as $packName) {
            $manifest = $loader->load($packName);
            $output->writeln(sprintf('  %-15s %s', $manifest->name, $manifest->description));

            if ($manifest->compliancePresets !== []) {
                $output->writeln(sprintf('                  Supports controls for: %s', implode(', ', $manifest->compliancePresets)));
            }
        }

        $output->newLine();
        $output->writeln('Usage: pulsar new my-app --pack=<pack-name>');

        return ExitCode::Success->value;
    }
}
