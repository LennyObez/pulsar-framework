<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Remove;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function is_file;
use function is_int;
use function sprintf;

/**
 * Remove a scaffolded port interface from a module.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class RemovePortCommand extends Command
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
        $this->name = 'remove:port';
        $this->description = 'Remove a scaffolded port interface from a module';
        $this->addArgument('name', 'Port name (e.g., PaymentProvider)', true);
        $this->addOption('module', 'Module name', 'm');
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
        $this->addOption('force', 'Skip confirmation prompt');
        $this->addOption('dry-run', 'List files that would be removed without deleting');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->resolveModuleContext($input, $output, 'Port');
        if (is_int($context)) {
            return $context;
        }

        [$name, $module, $modulePath] = $context;
        $force = $input->hasOption('force');
        $dryRun = $input->hasOption('dry-run');

        $portFile = $modulePath . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . $name . 'Interface.php';

        if (!is_file($portFile)) {
            $output->errorln(sprintf('Port "%sInterface" does not exist in module %s', $name, $module));
            return ExitCode::Error->value;
        }

        if ($dryRun) {
            $output->writeln(sprintf('Files that would be removed for port "%s":', $name));
            $output->newLine();
            $output->writeln('  ' . $portFile);
            return ExitCode::Success->value;
        }

        if (!$force && !$this->confirmAction($this->stdin, $output, sprintf('Remove port "%sInterface" from module "%s"?', $name, $module))) {
            $output->writeln('Aborted.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Removing port: %sInterface from %s', $name, $module));
        $output->newLine();

        $this->removeFileWithOutput($portFile, $output);

        $output->newLine();
        $output->success(sprintf('Port "%sInterface" removed from %s.', $name, $module));

        return ExitCode::Success->value;
    }
}
