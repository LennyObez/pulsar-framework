<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Remove;

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
        $context = $this->resolveModuleContext($input, $output, 'Adapter');
        if (is_int($context)) {
            return $context;
        }

        [$name, $module, $modulePath] = $context;
        $force = $input->hasOption('force');
        $dryRun = $input->hasOption('dry-run');

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
