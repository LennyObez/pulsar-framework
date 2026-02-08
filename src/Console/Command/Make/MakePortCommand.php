<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function is_dir;
use function is_string;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\Make\Template\PortTemplates;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;
use function trim;

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
        $name = $input->getArgument(0);
        $module = $input->getOption('module');
        $basePath = $input->getOption('path', 'app/Modules');
        $methodsRaw = $input->getOption('methods', '');

        if (!is_string($name) || $name === '') {
            $output->errorln('Port name is required.');
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

        /** @var list<string> $methods */
        $methods = [];
        if (is_string($methodsRaw) && $methodsRaw !== '') {
            $methods = array_values(array_filter(array_map(
                fn(string $m): string => trim($m),
                explode(',', $methodsRaw),
            )));
        }

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

        $contractsDir = $modulePath . DIRECTORY_SEPARATOR . 'Contracts';
        if (!is_dir($contractsDir)) {
            mkdir($contractsDir, 0o755, true);
        }

        $namespace = 'App\\Modules\\' . $module;
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
