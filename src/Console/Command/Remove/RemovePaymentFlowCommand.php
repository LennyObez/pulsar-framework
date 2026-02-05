<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Remove;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function is_int;
use function sprintf;

/**
 * Remove a scaffolded payment flow and its associated files.
 */
final class RemovePaymentFlowCommand extends Command
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
        $this->name = 'remove:payment-flow';
        $this->description = 'Remove a scaffolded payment flow and its files';
        $this->addArgument('name', 'Payment flow name (e.g., Checkout)', true);
        $this->addOption('module', 'Module name', 'm');
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
        $this->addOption('force', 'Skip confirmation prompt');
        $this->addOption('dry-run', 'List files that would be removed without deleting');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->resolveModuleContext($input, $output, 'Payment flow');
        if (is_int($context)) {
            return $context;
        }

        [$name, $module, $modulePath] = $context;
        $force = $input->hasOption('force');
        $dryRun = $input->hasOption('dry-run');

        // Collect files to remove (mirrors MakePaymentFlowCommand output)
        $files = [
            $modulePath . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . $name . 'ProviderInterface.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'Null' . $name . 'Provider.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Gateway' . DIRECTORY_SEPARATOR . $name . 'Gateway.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Gateway' . DIRECTORY_SEPARATOR . 'ParametersHasher.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . $name . 'Config.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . $name . 'Intent.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . $name . 'IntentStatus.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . $name . 'Charge.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . $name . 'ChargeStatus.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . $name . 'Refund.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . $name . 'RefundStatus.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Domain' . DIRECTORY_SEPARATOR . 'Money.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Exception' . DIRECTORY_SEPARATOR . $name . 'Exception.php',
            $modulePath . DIRECTORY_SEPARATOR . 'Exception' . DIRECTORY_SEPARATOR . $name . 'ProviderException.php',
            $modulePath . DIRECTORY_SEPARATOR . $name . 'ServiceProvider.php',
            $modulePath . DIRECTORY_SEPARATOR . 'README.md',
        ];

        // Test files
        $cwd = getcwd();
        if ($cwd !== false) {
            $testBase = $cwd . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
                . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $module;

            $files[] = $testBase . DIRECTORY_SEPARATOR . 'Gateway' . DIRECTORY_SEPARATOR . $name . 'GatewayTest.php';
        }

        // Filter to files that actually exist
        $existingFiles = array_filter($files, 'is_file');

        if ($existingFiles === []) {
            $output->errorln(sprintf('No payment flow files found for "%s" in module %s', $name, $module));
            return ExitCode::Error->value;
        }

        if ($dryRun) {
            $output->writeln(sprintf('Files that would be removed for payment flow "%s":', $name));
            $output->newLine();

            foreach ($existingFiles as $file) {
                $output->writeln('  ' . $file);
            }

            return ExitCode::Success->value;
        }

        if (!$force && !$this->confirmAction($this->stdin, $output, sprintf('Remove payment flow "%s" from module "%s"?', $name, $module))) {
            $output->writeln('Aborted.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Removing payment flow: %s from %s', $name, $module));
        $output->newLine();

        foreach ($existingFiles as $file) {
            $this->removeFileWithOutput($file, $output);
        }

        $output->newLine();
        $output->success(sprintf('Payment flow "%s" removed from %s.', $name, $module));

        return ExitCode::Success->value;
    }
}
