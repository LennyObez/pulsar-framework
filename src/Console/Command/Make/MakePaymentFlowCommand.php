<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use function is_dir;
use function is_string;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\Make\Template\PaymentFlowTemplates;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Scaffold a complete payment flow with idempotency, audit logging, and metrics.
 */
final class MakePaymentFlowCommand extends Command
{
    use ScaffoldTrait;

    private readonly PaymentFlowTemplates $templates;

    public function __construct()
    {
        $this->templates = new PaymentFlowTemplates();
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:payment-flow';
        $this->description = 'Generate a payment flow with idempotency and observability';
        $this->addArgument('name', 'Payment flow name (e.g., Checkout, Subscription)', true);
        $this->addOption('module', 'Module name', 'm');
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);
        $module = $input->getOption('module');
        $basePath = $input->getOption('path', 'app/Modules');

        if (!is_string($name) || $name === '') {
            $output->errorln('Payment flow name is required.');
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

        $namespace = 'App\\Modules\\' . $module;

        $output->writeln(sprintf('Creating payment flow: %s in %s', $name, $module));
        $output->newLine();

        // Ensure all directories exist
        foreach (['Contracts', 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure', 'Gateway', 'Config', 'Domain', 'Exception'] as $dir) {
            $fullDir = $modulePath . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($fullDir)) {
                mkdir($fullDir, 0o755, true);
                $output->writeln(sprintf('  Created %s/', $dir));
            }
        }

        // Create files
        $this->writeFiles($modulePath, [
            'Contracts' . DIRECTORY_SEPARATOR . $name . 'ProviderInterface.php' => $this->templates->providerInterface($name, $namespace),
            'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'Null' . $name . 'Provider.php' => $this->templates->nullProvider($name, $namespace),
            'Gateway' . DIRECTORY_SEPARATOR . $name . 'Gateway.php' => $this->templates->gateway($name, $namespace),
            'Gateway' . DIRECTORY_SEPARATOR . 'ParametersHasher.php' => $this->templates->parametersHasher($namespace),
            'Config' . DIRECTORY_SEPARATOR . $name . 'Config.php' => $this->templates->config($name, $namespace),
            'Domain' . DIRECTORY_SEPARATOR . $name . 'Intent.php' => $this->templates->intent($name, $namespace),
            'Domain' . DIRECTORY_SEPARATOR . $name . 'IntentStatus.php' => $this->templates->intentStatus($name, $namespace),
            'Domain' . DIRECTORY_SEPARATOR . $name . 'Charge.php' => $this->templates->charge($name, $namespace),
            'Domain' . DIRECTORY_SEPARATOR . $name . 'ChargeStatus.php' => $this->templates->chargeStatus($name, $namespace),
            'Domain' . DIRECTORY_SEPARATOR . $name . 'Refund.php' => $this->templates->refund($name, $namespace),
            'Domain' . DIRECTORY_SEPARATOR . $name . 'RefundStatus.php' => $this->templates->refundStatus($name, $namespace),
            'Domain' . DIRECTORY_SEPARATOR . 'Money.php' => $this->templates->money($namespace),
            'Exception' . DIRECTORY_SEPARATOR . $name . 'Exception.php' => $this->templates->exception($name, $namespace),
            'Exception' . DIRECTORY_SEPARATOR . $name . 'ProviderException.php' => $this->templates->providerException($name, $namespace),
            $name . 'ServiceProvider.php' => $this->templates->serviceProvider($name, $namespace),
            'README.md' => $this->templates->readme($name),
        ], $output);

        // Create tests
        $cwd = getcwd();
        if ($cwd !== false) {
            $testDir = $cwd . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
                . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $module
                . DIRECTORY_SEPARATOR . 'Gateway';

            if (!is_dir($testDir)) {
                mkdir($testDir, 0o755, true);
            }

            $this->writeFiles($testDir, [
                $name . 'GatewayTest.php' => $this->templates->gatewayTest($name, $module, $namespace),
            ], $output);
        }

        $output->newLine();
        $output->success(sprintf('Payment flow "%s" created in %s/', $name, $module));

        return ExitCode::Success->value;
    }
}
