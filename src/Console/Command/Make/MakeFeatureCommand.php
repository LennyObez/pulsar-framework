<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use function is_dir;
use function is_string;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\Make\Template\FeatureTemplates;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Scaffold a vertical feature slice within a module.
 */
final class MakeFeatureCommand extends Command
{
    use ScaffoldTrait;

    private readonly FeatureTemplates $templates;

    public function __construct()
    {
        $this->templates = new FeatureTemplates();
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:feature';
        $this->description = 'Generate a vertical feature slice in a module';
        $this->addArgument('name', 'Feature name (e.g., CreateOrder, RefundPayment)', true);
        $this->addOption('module', 'Module name', 'm');
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
        $this->addOption('method', 'HTTP method for the route', null, 'POST');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);
        $module = $input->getOption('module');
        $basePath = $input->getOption('path', 'app/Modules');
        $method = $input->getOption('method', 'POST');

        if (!is_string($name) || $name === '') {
            $output->errorln('Feature name is required.');
            return ExitCode::Invalid->value;
        }

        if (!is_string($module) || $module === '') {
            $output->errorln('Module name is required (--module).');
            return ExitCode::Invalid->value;
        }

        if (!is_string($basePath)) {
            $basePath = 'app/Modules';
        }

        if (!is_string($method)) {
            $method = 'POST';
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
        $featurePath = $modulePath . DIRECTORY_SEPARATOR . 'Features' . DIRECTORY_SEPARATOR . $name;

        if (is_dir($featurePath)) {
            $output->errorln(sprintf('Feature "%s" already exists in module %s', $name, $module));
            return ExitCode::Error->value;
        }

        $output->writeln(sprintf('Creating feature: %s in %s', $name, $module));
        $output->newLine();

        // Create feature directories
        $directories = ['', 'Contracts'];
        if (!$this->createDirectories($featurePath, $name, $directories, $output)) {
            return ExitCode::Error->value;
        }

        // Create feature files
        $this->writeFiles($featurePath, [
            'Contracts' . DIRECTORY_SEPARATOR . $name . 'HandlerInterface.php' => $this->templates->handlerInterface($name, $namespace),
            $name . 'Handler.php' => $this->templates->handler($name, $namespace),
            $name . 'HandlerTest.php' => $this->templates->handlerTest($name, $module, $namespace),
        ], $output);

        // Append route entry to module's routes.php
        $routesFile = $modulePath . DIRECTORY_SEPARATOR . 'routes.php';
        if (is_file($routesFile)) {
            $routeContent = file_get_contents($routesFile);
            if ($routeContent !== false) {
                // Insert before the closing of the closure
                $entry = $this->templates->routeEntry($name, $namespace, $method);
                $routeContent = str_replace('};', $entry . "\n};", $routeContent);
                file_put_contents($routesFile, $routeContent);
                $output->writeln('  Updated routes.php');
            }
        }

        $output->newLine();
        $output->success(sprintf('Feature "%s" created in %s/Features/%s/', $name, $module, $name));

        return ExitCode::Success->value;
    }
}
