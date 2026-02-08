<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Remove;

use function is_dir;
use function is_file;
use function is_string;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Remove a scaffolded adapter and its test from a module.
 */
final class RemoveAdapterCommand extends Command
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
        $this->name = 'remove:adapter';
        $this->description = 'Remove a scaffolded adapter and its test';
        $this->addArgument('name', 'Adapter name (e.g., StripePaymentProvider)', true);
        $this->addOption('module', 'Module name', 'm');
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
        $this->addOption('force', 'Skip confirmation prompt');
        $this->addOption('dry-run', 'List files that would be removed without deleting');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);
        $module = $input->getOption('module');
        $basePath = $input->getOption('path', 'app/Modules');
        $force = $input->hasOption('force');
        $dryRun = $input->hasOption('dry-run');

        if (!is_string($name) || $name === '') {
            $output->errorln('Adapter name is required.');
            return ExitCode::Invalid->value;
        }

        if (!is_string($module) || $module === '') {
            $output->errorln('Module name is required (--module).');
            return ExitCode::Invalid->value;
        }

        if (!is_string($basePath)) {
            $basePath = 'app/Modules';
        }

        $name = $this->toPascalCase($name);
        $module = $this->toPascalCase($module);

        $resolved = $this->resolveBasePath($basePath, 'app/Modules');
        if ($resolved === false) {
            $output->errorln('Failed to get current working directory.');
            return ExitCode::Error->value;
        }

        $modulePath = $resolved . DIRECTORY_SEPARATOR . $module;

        if (!is_dir($modulePath)) {
            $output->errorln(sprintf('Module "%s" does not exist at %s', $module, $modulePath));
            return ExitCode::Error->value;
        }

        $adapterFile = $modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . '.php';

        if (!is_file($adapterFile)) {
            $output->errorln(sprintf('Adapter "%s" does not exist in module %s', $name, $module));
            return ExitCode::Error->value;
        }

        // Find test file
        $cwd = getcwd();
        $testFile = $cwd !== false
            ? $cwd . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
                . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $module
                . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure'
                . DIRECTORY_SEPARATOR . $name . 'Test.php'
            : null;

        if ($dryRun) {
            $output->writeln(sprintf('Files that would be removed for adapter "%s":', $name));
            $output->newLine();
            $output->writeln('  ' . $adapterFile);
            if ($testFile !== null && is_file($testFile)) {
                $output->writeln('  ' . $testFile);
            }
            return ExitCode::Success->value;
        }

        if (!$force && !$this->confirmAction($this->stdin, $output, sprintf('Remove adapter "%s" from module "%s"?', $name, $module))) {
            $output->writeln('Aborted.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Removing adapter: %s from %s', $name, $module));
        $output->newLine();

        $this->removeFileWithOutput($adapterFile, $output);

        if ($testFile !== null) {
            $this->removeFileWithOutput($testFile, $output);
        }

        $output->newLine();
        $output->success(sprintf('Adapter "%s" removed from %s.', $name, $module));

        return ExitCode::Success->value;
    }
}
