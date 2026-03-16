<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\Make\Template\AdapterTemplates;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function count;
use function file_get_contents;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Scaffold an adapter implementing a port interface.
 */
final class MakeAdapterCommand extends Command
{
    use ScaffoldTrait;

    private readonly AdapterTemplates $templates;
    private readonly InterfaceParser $parser;

    public function __construct()
    {
        $this->templates = new AdapterTemplates();
        $this->parser = new InterfaceParser();
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:adapter';
        $this->description = 'Generate an adapter implementing a port interface';
        $this->addArgument('name', 'Adapter name (e.g., StripePaymentProvider)', true);
        $this->addOption('port', 'Port interface name to implement');
        $this->addOption('module', 'Module name', 'm');
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);
        $portName = $input->getOption('port');
        $module = $input->getOption('module');
        $basePath = $input->getOption('path', 'app/Modules');

        if (!is_string($name) || $name === '') {
            $output->errorln('Adapter name is required.');
            return ExitCode::Invalid->value;
        }

        if (!is_string($module) || $module === '') {
            $output->errorln('Module name is required (--module).');
            return ExitCode::Invalid->value;
        }

        if (!is_string($portName) || $portName === '') {
            $output->errorln('Port name is required (--port).');
            return ExitCode::Invalid->value;
        }

        if (!is_string($basePath)) {
            $basePath = 'app/Modules';
        }

        $name = $this->toPascalCase($name);
        $module = $this->toPascalCase($module);
        $portName = $this->toPascalCase($portName);

        $resolved = $this->resolveBasePathOrFail($basePath, 'app/Modules', $output);
        if (is_int($resolved)) {
            return $resolved;
        }

        $modulePath = $resolved . DIRECTORY_SEPARATOR . $module;
        $namespace = 'App\\Modules\\' . $module;

        if (!is_dir($modulePath)) {
            $output->errorln(sprintf('Module "%s" does not exist at %s', $module, $modulePath));
            return ExitCode::Error->value;
        }

        // Parse port interface if it exists on disk
        /** @var list<MethodSignature> $methods */
        $methods = [];
        $portFile = $modulePath . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . $portName . 'Interface.php';
        if (is_file($portFile)) {
            $source = file_get_contents($portFile);
            if ($source !== false) {
                $methods = $this->parser->parse($source);
                $output->writeln(sprintf('  Parsed %d method(s) from %sInterface', count($methods), $portName));
            }
        }

        // Ensure Internal/Infrastructure directory exists
        $infraDir = $modulePath . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure';
        if (!is_dir($infraDir)) {
            mkdir($infraDir, 0o750, true);
        }

        $output->writeln(sprintf('Creating adapter: %s in %s', $name, $module));
        $output->newLine();

        $this->writeFiles($infraDir, [
            $name . '.php' => $this->templates->adapter($name, $namespace, $portName, $methods),
        ], $output);

        // Create test
        $cwd = getcwd();
        if ($cwd !== false) {
            $testDir = $cwd . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
                . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $module
                . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure';

            if (!is_dir($testDir)) {
                mkdir($testDir, 0o750, true);
            }

            $this->writeFiles($testDir, [
                $name . 'Test.php' => $this->templates->adapterTest($name, $module, $namespace, $portName),
            ], $output);
        }

        $output->newLine();
        $output->success(sprintf('Adapter "%s" created in %s/Internal/Infrastructure/', $name, $module));

        return ExitCode::Success->value;
    }
}
