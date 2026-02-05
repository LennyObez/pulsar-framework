<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Remove;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function is_dir;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Remove a scaffolded extension directory.
 */
final class RemoveExtensionCommand extends Command
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
        $this->name = 'remove:extension';
        $this->description = 'Remove a scaffolded extension';
        $this->addArgument('name', 'Extension name (e.g., my-extension)', true);
        $this->addOption('path', 'Base path for extensions', 'p', 'extensions');
        $this->addOption('force', 'Skip confirmation prompt');
        $this->addOption('dry-run', 'List files that would be removed without deleting');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);
        $basePath = $input->getOption('path', 'extensions');
        $force = $input->hasOption('force');
        $dryRun = $input->hasOption('dry-run');

        if (!is_string($name) || $name === '') {
            $output->errorln('Extension name is required.');
            return ExitCode::Invalid->value;
        }

        if (!is_string($basePath)) {
            $basePath = 'extensions';
        }

        $dirName = $this->toKebabCase($name);

        $resolved = $this->resolveBasePathOrFail($basePath, 'extensions', $output);
        if (is_int($resolved)) {
            return $resolved;
        }

        $extensionPath = $resolved . DIRECTORY_SEPARATOR . $dirName;

        if (!is_dir($extensionPath)) {
            $output->errorln(sprintf('Extension "%s" does not exist at %s', $dirName, $extensionPath));
            return ExitCode::Error->value;
        }

        if ($dryRun) {
            $output->writeln(sprintf('Files that would be removed for extension "%s":', $dirName));
            $output->newLine();

            foreach ($this->listFilesRecursive($extensionPath) as $file) {
                $output->writeln('  ' . $file);
            }

            return ExitCode::Success->value;
        }

        if (!$force && !$this->confirmAction($this->stdin, $output, sprintf('Remove extension "%s" and all its files?', $dirName))) {
            $output->writeln('Aborted.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Removing extension: %s', $dirName));
        $output->newLine();

        $this->removeDirectoryRecursive($extensionPath);
        $output->writeln(sprintf('  Removed %s/', $extensionPath));

        $output->newLine();
        $output->success(sprintf('Extension "%s" removed successfully.', $dirName));

        return ExitCode::Success->value;
    }
}
