<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Remove;

use function is_dir;
use function is_string;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Remove a scaffolded module and its associated tests.
 */
final class RemoveModuleCommand extends Command
{
    use ScaffoldTrait;

    /** @var resource */
    private readonly mixed $stdin;

    /**
     * @param resource|null $stdin Readable stream for interactive prompts (default: STDIN)
     */
    public function __construct(mixed $stdin = null)
    {
        $this->stdin = $stdin ?? STDIN;
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'remove:module';
        $this->description = 'Remove a scaffolded module and its tests';
        $this->addArgument('name', 'Module name (e.g., Billing)', true);
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
        $this->addOption('force', 'Skip confirmation prompt');
        $this->addOption('dry-run', 'List files that would be removed without deleting');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);
        $basePath = $input->getOption('path', 'app/Modules');
        $force = $input->hasOption('force');
        $dryRun = $input->hasOption('dry-run');

        if (!is_string($name) || $name === '') {
            $output->errorln('Module name is required.');
            return ExitCode::Invalid->value;
        }

        if (!is_string($basePath)) {
            $basePath = 'app/Modules';
        }

        $name = $this->toPascalCase($name);

        $resolved = $this->resolveBasePath($basePath, 'app/Modules');
        if ($resolved === false) {
            $output->errorln('Failed to get current working directory.');
            return ExitCode::Error->value;
        }

        $modulePath = $resolved . DIRECTORY_SEPARATOR . $name;

        if (!is_dir($modulePath)) {
            $output->errorln(sprintf('Module "%s" does not exist at %s', $name, $modulePath));
            return ExitCode::Error->value;
        }

        // Collect paths to remove
        $cwd = getcwd();
        $testPath = $cwd !== false
            ? $cwd . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $name
            : null;

        if ($dryRun) {
            $output->writeln(sprintf('Files that would be removed for module "%s":', $name));
            $output->newLine();

            foreach ($this->listFilesRecursive($modulePath) as $file) {
                $output->writeln('  ' . $file);
            }

            if ($testPath !== null && is_dir($testPath)) {
                foreach ($this->listFilesRecursive($testPath) as $file) {
                    $output->writeln('  ' . $file);
                }
            }

            return ExitCode::Success->value;
        }

        if (!$force && !$this->confirmAction($this->stdin, $output, sprintf('Remove module "%s" and all its files?', $name))) {
            $output->writeln('Aborted.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Removing module: %s', $name));
        $output->newLine();

        $this->removeDirectoryRecursive($modulePath);
        $output->writeln(sprintf('  Removed %s/', $modulePath));

        if ($testPath !== null && is_dir($testPath)) {
            $this->removeDirectoryRecursive($testPath);
            $output->writeln(sprintf('  Removed %s/', $testPath));
        }

        $output->newLine();
        $output->success(sprintf('Module "%s" removed successfully.', $name));

        return ExitCode::Success->value;
    }
}
