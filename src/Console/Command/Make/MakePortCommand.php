<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use function is_dir;
use function is_int;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\Make\Template\PortTemplates;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Scaffold a new port (interface) in a module's Contracts directory.
 */
final class MakePortCommand extends Command
{
    use ScaffoldTrait;

    private readonly PortTemplates $templates;

    public function __construct()
    {
        $this->templates = new PortTemplates();
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:port';
        $this->description = 'Generate a new port interface in a module';
        $this->addArgument('name', 'Port name (e.g., PaymentProvider)', true);
        $this->addOption('module', 'Module name', 'm');
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
        $this->addOption('methods', 'Comma-separated method names');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->resolveModuleContext($input, $output, 'Port');
        if (is_int($context)) {
            return $context;
        }

        [$name, $module, $modulePath, $namespace] = $context;

        $methods = $this->parseCommaSeparatedOption($input->getOption('methods', ''));

        $contractsDir = $modulePath . DIRECTORY_SEPARATOR . 'Contracts';
        if (!is_dir($contractsDir)) {
            mkdir($contractsDir, 0o755, true);
        }
        $fileName = $name . 'Interface.php';

        $output->writeln(sprintf('Creating port: %sInterface in %s', $name, $module));
        $output->newLine();

        $this->writeFiles($contractsDir, [
            $fileName => $this->templates->portInterface($name, $namespace, $methods),
        ], $output);

        $output->newLine();
        $output->success(sprintf('Port "%sInterface" created in %s/Contracts/', $name, $module));

        return ExitCode::Success->value;
    }
}
