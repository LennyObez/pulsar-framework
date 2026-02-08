<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Remove;

use function is_dir;
use function is_file;
use function is_int;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Remove a scaffolded feature slice from a module.
 */
final class RemoveFeatureCommand extends Command
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
        $this->name = 'remove:feature';
        $this->description = 'Remove a scaffolded feature slice from a module';
        $this->addArgument('name', 'Feature name (e.g., CreateOrder)', true);
        $this->addOption('module', 'Module name', 'm');
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
        $this->addOption('force', 'Skip confirmation prompt');
        $this->addOption('dry-run', 'List files that would be removed without deleting');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->resolveModuleContext($input, $output, 'Feature');
        if (is_int($context)) {
            return $context;
        }

        [$name, $module, $modulePath] = $context;
        $force = $input->hasOption('force');
        $dryRun = $input->hasOption('dry-run');

        $featurePath = $modulePath . DIRECTORY_SEPARATOR . 'Features' . DIRECTORY_SEPARATOR . $name;

        if (!is_dir($featurePath)) {
            $output->errorln(sprintf('Feature "%s" does not exist in module %s', $name, $module));
            return ExitCode::Error->value;
        }

        if ($dryRun) {
            $output->writeln(sprintf('Files that would be removed for feature "%s":', $name));
            $output->newLine();

            foreach ($this->listFilesRecursive($featurePath) as $file) {
                $output->writeln('  ' . $file);
            }

            $output->writeln('  Route entry would be removed from routes.php');
            return ExitCode::Success->value;
        }

        if (!$force && !$this->confirmAction($this->stdin, $output, sprintf('Remove feature "%s" from module "%s"?', $name, $module))) {
            $output->writeln('Aborted.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Removing feature: %s from %s', $name, $module));
        $output->newLine();

        $this->removeDirectoryRecursive($featurePath);
        $output->writeln(sprintf('  Removed %s/', $featurePath));

        // Remove route entry from routes.php
        $routesFile = $modulePath . DIRECTORY_SEPARATOR . 'routes.php';
        if (is_file($routesFile)) {
            $content = file_get_contents($routesFile);
            if ($content !== false) {
                $pattern = '/.*' . preg_quote($name, '/') . '.*\n?/';
                $updated = preg_replace($pattern, '', $content);
                if ($updated !== null && $updated !== $content) {
                    file_put_contents($routesFile, $updated);
                    $output->writeln('  Updated routes.php');
                }
            }
        }

        $output->newLine();
        $output->success(sprintf('Feature "%s" removed from %s.', $name, $module));

        return ExitCode::Success->value;
    }
}
