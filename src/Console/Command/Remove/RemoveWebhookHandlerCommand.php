<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Remove;

use function is_int;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Remove a scaffolded webhook handler and its associated files.
 */
final class RemoveWebhookHandlerCommand extends Command
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
        $this->name = 'remove:webhook-handler';
        $this->description = 'Remove a scaffolded webhook handler and its files';
        $this->addArgument('name', 'Webhook handler name (e.g., Stripe)', true);
        $this->addOption('module', 'Module name', 'm');
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
        $this->addOption('force', 'Skip confirmation prompt');
        $this->addOption('dry-run', 'List files that would be removed without deleting');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->resolveModuleContext($input, $output, 'Webhook handler');
        if (is_int($context)) {
            return $context;
        }

        [$name, $module, $modulePath] = $context;
        $force = $input->hasOption('force');
        $dryRun = $input->hasOption('dry-run');

        // Collect files to remove (mirrors MakeWebhookHandlerCommand output)
        $files = [
            $modulePath . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . $name . 'WebhookHandlerInterface.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'WebhookHandler.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'HmacVerifier.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'InMemory' . $name . 'EventLog.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . $name . 'WebhookController.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . $name . 'WebhookConfig.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . $name . 'WebhookEvent.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . $name . 'WebhookEventType.php',
        ];

        // Test files
        $cwd = getcwd();
        if ($cwd !== false) {
            $testBase = $cwd . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
                . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $module;

            $files[] = $testBase . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'WebhookHandlerTest.php';
            $files[] = $testBase . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . $name . 'WebhookControllerTest.php';
        }

        // Filter to files that actually exist
        $existingFiles = array_filter($files, 'is_file');

        if ($existingFiles === []) {
            $output->errorln(sprintf('No webhook handler files found for "%s" in module %s', $name, $module));
            return ExitCode::Error->value;
        }

        if ($dryRun) {
            $output->writeln(sprintf('Files that would be removed for webhook handler "%s":', $name));
            $output->newLine();

            foreach ($existingFiles as $file) {
                $output->writeln('  ' . $file);
            }

            return ExitCode::Success->value;
        }

        if (!$force && !$this->confirmAction($this->stdin, $output, sprintf('Remove webhook handler "%s" from module "%s"?', $name, $module))) {
            $output->writeln('Aborted.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Removing webhook handler: %s from %s', $name, $module));
        $output->newLine();

        foreach ($existingFiles as $file) {
            $this->removeFileWithOutput($file, $output);
        }

        $output->newLine();
        $output->success(sprintf('Webhook handler "%s" removed from %s.', $name, $module));

        return ExitCode::Success->value;
    }
}
