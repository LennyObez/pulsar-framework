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
 * Remove a scaffolded event ingestion pipeline and its associated files.
 */
final class RemoveEventIngestionCommand extends Command
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
        $this->name = 'remove:event-ingestion';
        $this->description = 'Remove a scaffolded event ingestion pipeline and its files';
        $this->addArgument('name', 'Ingestion name (e.g., GitHub)', true);
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
            $output->errorln('Ingestion name is required.');
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

        // Collect files to remove (mirrors MakeEventIngestionCommand output)
        $files = [
            $modulePath . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . $name . 'EventHandlerInterface.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'EventHandler.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'HmacVerifier.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'InMemory' . $name . 'EventLog.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . $name . 'WebhookController.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . $name . 'IngestionConfig.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . $name . 'Event.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . $name . 'EventType.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Exception' . DIRECTORY_SEPARATOR . $name . 'IngestionException.php',
            $modulePath . DIRECTORY_SEPARATOR . $name . 'IngestionServiceProvider.php',
            $modulePath . DIRECTORY_SEPARATOR . 'README.md',
        ];

        // Test files
        $cwd = getcwd();
        if ($cwd !== false) {
            $testBase = $cwd . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
                . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $module;

            $files[] = $testBase . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'EventHandlerTest.php';
            $files[] = $testBase . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . $name . 'WebhookControllerTest.php';
        }

        // Filter to files that actually exist
        $existingFiles = array_filter($files, 'is_file');

        if ($existingFiles === []) {
            $output->errorln(sprintf('No event ingestion files found for "%s" in module %s', $name, $module));
            return ExitCode::Error->value;
        }

        if ($dryRun) {
            $output->writeln(sprintf('Files that would be removed for event ingestion "%s":', $name));
            $output->newLine();

            foreach ($existingFiles as $file) {
                $output->writeln('  ' . $file);
            }

            return ExitCode::Success->value;
        }

        if (!$force && !$this->confirmAction($this->stdin, $output, sprintf('Remove event ingestion "%s" from module "%s"?', $name, $module))) {
            $output->writeln('Aborted.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Removing event ingestion: %s from %s', $name, $module));
        $output->newLine();

        foreach ($existingFiles as $file) {
            $this->removeFileWithOutput($file, $output);
        }

        $output->newLine();
        $output->success(sprintf('Event ingestion "%s" removed from %s.', $name, $module));

        return ExitCode::Success->value;
    }
}
